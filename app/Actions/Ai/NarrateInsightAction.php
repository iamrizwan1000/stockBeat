<?php

namespace App\Actions\Ai;

use App\Support\Ai\AiProviderManager;
use Throwable;

/**
 * Proactive AI Insights' narration step (Plan §4.12, Premium). Only ever
 * rephrases real, already-detected facts (`DetectAiInsightsAction` decides
 * *what's* notable via deterministic thresholds — the same discipline as
 * `order_spike`/`refund_spike`/`low_stock` already use — this just writes
 * the sentence). Resilient like `NarrateDigestAction`: any provider
 * failure returns `null` rather than throwing, so a scheduled scan across
 * many teams never aborts because one team's narration call failed.
 */
class NarrateInsightAction
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You write a single short, direct push notification alerting an online seller to something that needs their attention right now. You will be given one or more real facts — use ONLY those facts, never invent, estimate, or add anything else. If given more than one fact, lead with the most urgent one. Keep it under 200 characters.
        PROMPT;

    public function __construct(
        private readonly AiProviderManager $providerManager,
    ) {}

    /**
     * @param  array<int, string>  $facts
     */
    public function handle(array $facts): ?string
    {
        try {
            $result = $this->providerManager->driver()->chat([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => implode(' ', $facts)],
            ], []);
        } catch (Throwable) {
            return null;
        }

        return $result->content;
    }
}
