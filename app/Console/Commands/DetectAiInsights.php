<?php

namespace App\Console\Commands;

use App\Actions\Ai\DetectAiInsightsAction;
use App\Actions\Billing\ResolveEntitlementsAction;
use App\Models\Rule;
use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Scans every team with at least one enabled `ai_insight` rule for
 * something notable and, on Premium (`plan_limits.ai_proactive_insights_enabled`),
 * fires it (Plan §4.12). The plan check happens here rather than at rule
 * creation alone — a comp/downgrade/expiry can change a team's plan
 * without touching its rules, so this must be re-checked on every run
 * (same reasoning as `ResolveEntitlementsAction` being computed live
 * everywhere else, not cached at creation time).
 */
class DetectAiInsights extends Command
{
    protected $signature = 'ai:detect-insights';

    protected $description = 'Scan teams with Proactive AI Insights enabled and fire their ai_insight rule(s) if something notable is found';

    public function handle(DetectAiInsightsAction $action, ResolveEntitlementsAction $resolveEntitlements): int
    {
        $teamIds = Rule::query()
            ->where('trigger', Rule::TRIGGER_AI_INSIGHT)
            ->where('enabled', true)
            ->distinct()
            ->pluck('team_id');

        $scanned = 0;

        Team::query()->whereIn('id', $teamIds)->chunkById(100, function ($teams) use ($action, $resolveEntitlements, &$scanned) {
            foreach ($teams as $team) {
                $limits = $resolveEntitlements->handle($team)['limits'];

                if (empty($limits['ai_proactive_insights_enabled'])) {
                    continue;
                }

                $action->handle($team);
                $scanned++;
            }
        });

        $this->info("Scanned {$scanned} team(s) for AI insights.");

        return self::SUCCESS;
    }
}
