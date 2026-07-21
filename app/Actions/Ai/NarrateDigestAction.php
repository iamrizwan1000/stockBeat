<?php

namespace App\Actions\Ai;

use App\Support\Ai\AiProviderManager;
use Throwable;

/**
 * AI-narrated digest (Plan §4.12): turns the digest's own real, already-
 * computed numbers into a natural sentence instead of the fixed "Yesterday:
 * N orders, $X" template. Never invents anything — the AI only rephrases
 * numbers it's handed, same grounding discipline as the Data Copilot.
 *
 * Deliberately resilient: any failure (no active provider, a bad key, an
 * outage) returns `null` rather than throwing, so a digest can never fail
 * to send just because narration failed — `SendMorningDigestAction` falls
 * back to its own template body in that case. Not tool-calling (no data to
 * fetch — the caller already has the numbers), and not counted against
 * `ai_usage_ledger`'s question quota since it's system-initiated, not a
 * question the seller asked.
 */
class NarrateDigestAction
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You write a one-sentence daily sales summary for a push notification to an online seller, in a friendly, direct tone. Use ONLY the numbers given to you — never invent, estimate, or add anything not provided. Keep it under 200 characters.
        PROMPT;

    public function __construct(
        private readonly AiProviderManager $providerManager,
    ) {}

    public function handle(int $ordersCount, float $revenue, ?string $bestSeller): ?string
    {
        $facts = "Orders: {$ordersCount}. Revenue: \${$this->formatMoney($revenue)}.";

        if ($bestSeller !== null) {
            $facts .= " Best seller: {$bestSeller}.";
        }

        try {
            $result = $this->providerManager->driver()->chat([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $facts],
            ], []);
        } catch (Throwable) {
            return null;
        }

        return $result->content;
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2);
    }
}
