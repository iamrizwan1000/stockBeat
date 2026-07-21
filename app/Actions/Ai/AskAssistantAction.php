<?php

namespace App\Actions\Ai;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Exceptions\Ai\AiProviderException;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiUsageLedger;
use App\Models\Team;
use App\Models\User;
use App\Support\Ai\AiProviderManager;
use App\Support\Ai\AssistantToolRegistry;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The AI Assistant (Plan §4.12): a tool-calling loop that only ever answers
 * from real tool-call results (`AssistantToolRegistry`), never a
 * free-associated guess. Two distinct, separately-gated modes:
 *
 *  - `MODE_DATA` (the Data Copilot): sales/orders/inventory/etc. Requires
 *    `plan_limits.ai_enabled` and is quota-metered via `ai_usage_ledger` —
 *    locked entirely on Free.
 *  - `MODE_HELP` (App Help): how-to, plan/billing, and connection
 *    troubleshooting questions. Available on **every** plan including
 *    Free, and never touches the question quota — this is the surface
 *    that's supposed to deflect support tickets, so it can't itself be a
 *    paywall (§4.12: "Free because it deflects support tickets... rather
 *    than costing per-question against a business-data quota").
 *
 * The mode is an explicit request parameter rather than something inferred
 * from the question text — inferring intent from free text would make the
 * Free-tier guarantee unreliable (a misclassified question could either
 * leak a paid answer or wrongly refuse a help question).
 */
class AskAssistantAction
{
    public const MODE_DATA = 'data';

    public const MODE_HELP = 'help';

    private const MAX_TOOL_ROUNDS = 5;

    private const DATA_SYSTEM_PROMPT = <<<'PROMPT'
        You are the StockBeat AI Assistant's Data Copilot, embedded in a multichannel order-management app for online sellers. You answer questions about the seller's own store data — sales, orders, inventory, profit/margin, restock timing, and account/plan status — using ONLY the tools provided to you. Never invent, estimate, or guess a number; every figure in your answer must come from a tool result.

        Profit/margin (get_profit_summary) and restock timing (get_restock_recommendations) are only ever partially answerable: profit only covers order items whose product has a seller-entered cost price set, and restock estimates only cover products with actual recent sales. When a tool result says something was excluded (e.g. "units_sold_missing_cost_price"), mention that honestly rather than implying the figure is complete.

        If a question needs data you don't have a tool for (e.g. advertising spend, discount/coupon totals on non-WooCommerce stores, anything outside this app), say so plainly and explain what you can't see, rather than speculating.

        Be concise and specific. When you cite a figure, state which time range it covers (today/7d/30d) since the seller may not have said one explicitly — default to "today" unless they imply otherwise.
        PROMPT;

    private const HELP_SYSTEM_PROMPT = <<<'PROMPT'
        You are the StockBeat AI Assistant's App Help, available to every seller regardless of plan. You answer how-to questions about using StockBeat, questions about the seller's plan/billing/subscription, and connection troubleshooting (using the get_connection_health tool) using ONLY the tools provided to you.

        You do NOT have access to the seller's sales, order, or inventory data in this mode — that's the paid Data Copilot. If asked a business-data question (revenue, orders, inventory, top products, etc.), say plainly that this needs the Data Copilot and, if their plan doesn't include it, that upgrading unlocks it — never guess or fabricate a business figure.

        Be concise and friendly.
        PROMPT;

    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
        private readonly AiProviderManager $providerManager,
        private readonly AssistantToolRegistry $tools,
    ) {}

    public function handle(Team $team, User $user, string $question, ?AiConversation $conversation, string $mode = self::MODE_DATA): AiConversation
    {
        $limits = $this->resolveEntitlements->handle($team)['limits'];
        $monthlyLimit = null;
        $usedThisMonth = 0;

        if ($mode === self::MODE_DATA) {
            if (empty($limits['ai_enabled'])) {
                throw ValidationException::withMessages([
                    'question' => 'The AI Data Copilot isn\'t available on your current plan. Upgrade to ask questions about your store, or use App Help for how-to/billing questions.',
                ]);
            }

            $monthlyLimit = AiUsageLedger::effectiveMonthlyLimit($team->id, $limits['ai_questions_monthly'] ?? null);
            $usedThisMonth = AiUsageLedger::questionsUsedThisMonth($team->id);

            if ($monthlyLimit !== null && $usedThisMonth >= $monthlyLimit) {
                throw ValidationException::withMessages([
                    'question' => "You've used all {$monthlyLimit} AI questions included in your plan this month. Upgrade or wait for next month's reset.",
                ]);
            }
        }

        $conversation ??= AiConversation::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'title' => Str::limit($question, 60),
        ]);

        $systemPrompt = $mode === self::MODE_DATA ? self::DATA_SYSTEM_PROMPT : self::HELP_SYSTEM_PROMPT;
        $messages = $this->buildMessages($conversation, $question, $systemPrompt);

        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => AiMessage::ROLE_USER,
            'content' => $question,
        ]);

        $provider = $this->providerManager->driver();

        $toolDefinitions = $mode === self::MODE_DATA
            ? [...$this->tools->appHelpDefinitions(), ...$this->tools->dataDefinitions()]
            : $this->tools->appHelpDefinitions();

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $result = $provider->chat($messages, $toolDefinitions);

            if (! $result->hasToolCalls()) {
                AiMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'role' => AiMessage::ROLE_ASSISTANT,
                    'content' => $result->content,
                ]);

                if ($mode === self::MODE_DATA) {
                    $this->debitQuota($team, $monthlyLimit, $usedThisMonth);
                }

                return $conversation;
            }

            AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => AiMessage::ROLE_ASSISTANT,
                'content' => $result->content,
                'tool_calls' => $result->toolCalls,
            ]);

            $messages[] = [
                'role' => 'assistant',
                'content' => $result->content,
                'tool_calls' => $result->toolCalls,
            ];

            foreach ($result->toolCalls as $call) {
                $toolResult = $this->tools->call($call['name'], $call['arguments'], $team);
                $encodedResult = json_encode($toolResult);

                AiMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'role' => AiMessage::ROLE_TOOL,
                    'content' => $encodedResult,
                    'tool_calls' => [['id' => $call['id'], 'name' => $call['name']]],
                ]);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'name' => $call['name'],
                    'content' => $encodedResult,
                ];
            }
        }

        throw new AiProviderException('The AI Assistant could not produce an answer after several tool calls — try rephrasing the question.');
    }

    /**
     * Replays this conversation's prior user/assistant Q&A turns (not the
     * raw tool round trips — those were only ever needed within the
     * request that produced them) plus the system prompt, so a follow-up
     * question has context without resending every historical tool call.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildMessages(AiConversation $conversation, string $newQuestion, string $systemPrompt): array
    {
        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        $history = $conversation->messages()
            ->whereIn('role', [AiMessage::ROLE_USER, AiMessage::ROLE_ASSISTANT])
            ->whereNull('tool_calls')
            ->orderBy('id')
            ->get(['role', 'content']);

        foreach ($history as $entry) {
            $messages[] = ['role' => $entry->role, 'content' => $entry->content];
        }

        $messages[] = ['role' => 'user', 'content' => $newQuestion];

        return $messages;
    }

    private function debitQuota(Team $team, ?int $monthlyLimit, int $usedBeforeThisCall): void
    {
        $balanceAfter = $monthlyLimit === null ? -1 : max($monthlyLimit - ($usedBeforeThisCall + 1), 0);

        AiUsageLedger::query()->create([
            'team_id' => $team->id,
            'delta' => -1,
            'reason' => AiUsageLedger::REASON_QUESTION,
            'balance_after' => $balanceAfter,
        ]);
    }
}
