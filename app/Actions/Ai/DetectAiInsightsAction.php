<?php

namespace App\Actions\Ai;

use App\Actions\Analytics\GetAnalyticsSummaryAction;
use App\Actions\Rules\RuleEvaluationAction;
use App\Models\Product;
use App\Models\Rule;
use App\Models\StoreConnection;
use App\Models\Team;

/**
 * Proactive AI Insights (Plan §4.12, Premium): scans one team for
 * something genuinely notable, using the same kind of deterministic
 * threshold checks the rules engine already trusts for `order_spike`/
 * `refund_spike`/`low_stock` — never an AI judgment call about what
 * *counts* as notable, only about how to phrase it once something real
 * crosses a real threshold (`NarrateInsightAction`). Fires every enabled
 * `ai_insight` rule on the team through the ordinary `RuleEvaluationAction`
 * path, so quiet hours/cooldown (`controls.cooldown_minutes`, since
 * `alreadyFired()`'s hard dedup doesn't apply to order-less triggers) work
 * exactly like every other order-less trigger.
 */
class DetectAiInsightsAction
{
    private const REVENUE_DROP_THRESHOLD_PCT = -25.0;

    private const LOW_STOCK_THRESHOLD = 5;

    public function __construct(
        private readonly GetAnalyticsSummaryAction $analyticsSummary,
        private readonly NarrateInsightAction $narrateInsight,
        private readonly RuleEvaluationAction $ruleEvaluation,
    ) {}

    public function handle(Team $team): void
    {
        $rules = Rule::query()
            ->where('team_id', $team->id)
            ->where('trigger', Rule::TRIGGER_AI_INSIGHT)
            ->where('enabled', true)
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $facts = $this->collectFacts($team);

        if ($facts === []) {
            return;
        }

        $insight = $this->narrateInsight->handle($facts);

        if ($insight === null || $insight === '') {
            return;
        }

        foreach ($rules as $rule) {
            $this->ruleEvaluation->handle($rule, Rule::TRIGGER_AI_INSIGHT, null, ['insight' => $insight]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function collectFacts(Team $team): array
    {
        $facts = [];

        $summary = $this->analyticsSummary->handle($team, 'today');
        $changePct = $summary['comparison']['change_pct'] ?? null;

        if ($changePct !== null && $changePct <= self::REVENUE_DROP_THRESHOLD_PCT) {
            $facts[] = sprintf(
                'Revenue today is down %.0f%% vs yesterday ($%.2f so far today).',
                abs($changePct),
                $summary['total']['revenue'],
            );
        }

        $troubledConnections = StoreConnection::query()
            ->where('team_id', $team->id)
            ->whereIn('status', [StoreConnection::STATUS_NEEDS_REAUTH, StoreConnection::STATUS_DISCONNECTED])
            ->pluck('name');

        foreach ($troubledConnections as $name) {
            $facts[] = "Store connection \"{$name}\" needs attention — it's disconnected or needs reauthorization.";
        }

        $lowStockCount = Product::query()
            ->where('team_id', $team->id)
            ->whereNotNull('stock_quantity')
            ->where('stock_quantity', '<=', self::LOW_STOCK_THRESHOLD)
            ->count();

        if ($lowStockCount > 0) {
            $facts[] = "{$lowStockCount} product(s) have ".self::LOW_STOCK_THRESHOLD.' or fewer units left in stock.';
        }

        return $facts;
    }
}
