<?php

namespace App\Actions\Ai;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Exceptions\Ai\AiProviderException;
use App\Http\Requests\Rules\StoreRuleRequest;
use App\Models\Rule;
use App\Models\Team;
use App\Support\Ai\AiProviderManager;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Natural-language Rule Builder (Plan §4.12, Pro+): "notify me by text
 * whenever an eBay order is over $200" → a structured rule draft, run
 * through the exact same validation `StoreRuleRequest` uses so a draft can
 * never be shown as "ready" unless `CreateRuleAction` would actually
 * accept it. Never persists — the caller must POST the draft to the
 * ordinary `POST /rules` endpoint after the seller confirms it.
 */
class GenerateRuleFromPromptAction
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You convert a seller's plain-English request into a StockBeat notification rule. Respond with ONLY a single JSON object — no prose, no markdown fences — matching this exact shape:

        {
          "name": "short human-readable rule name",
          "trigger": one of ["new_order","high_value_order","unfulfilled_after_x","ship_by_deadline","refund_requested","order_cancelled","payment_failed","negative_review","low_stock","order_spike","refund_spike","digest"],
          "conditions": { "all": [ { "field": "...", "operator": "...", "value": ... } ] } or null,
          "actions": [ { "type": "push" } ] and/or [ { "type": "email" } ] and/or [ { "type": "sms" } ],
          "controls": { ...trigger-specific settings, e.g. "threshold_hours" for unfulfilled_after_x, "spike_count"/"spike_window_minutes" for order_spike/refund_spike, "low_stock_threshold" for low_stock } or {}
        }

        Condition fields you may use: channel, store, total, sku, product, quantity, customer_country, repeat_buyer, shipping_method, tag. Operators: =, !=, >, >=, <, <=, contains.

        If the request is ambiguous or doesn't map to a real trigger, still return your best-effort JSON — validation happens separately and the seller confirms before anything is saved.
        PROMPT;

    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
        private readonly AiProviderManager $providerManager,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Team $team, string $prompt): array
    {
        $limits = $this->resolveEntitlements->handle($team)['limits'];

        if (empty($limits['ai_rule_builder_enabled'])) {
            throw ValidationException::withMessages([
                'prompt' => 'The AI rule builder requires the Pro plan or higher.',
            ]);
        }

        $provider = $this->providerManager->driver();

        $result = $provider->chat([
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $prompt],
        ], []);

        $draft = json_decode((string) $result->content, true);

        if (! is_array($draft)) {
            throw new AiProviderException("Couldn't turn that into a rule — try rephrasing it more concretely.");
        }

        $validator = Validator::make($draft, (new StoreRuleRequest)->rules());

        return [
            'draft' => $draft,
            'valid' => ! $validator->fails(),
            'errors' => $validator->fails() ? $validator->errors()->toArray() : null,
            'trigger_is_advanced' => in_array($draft['trigger'] ?? null, Rule::advancedTriggers(), true),
        ];
    }
}
