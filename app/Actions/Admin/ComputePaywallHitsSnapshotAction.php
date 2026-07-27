<?php

namespace App\Actions\Admin;

use App\Models\PaywallHit;
use Illuminate\Support\Facades\DB;

/**
 * Real paywall-rejection counts (Plan §8.7.1 gap, closed 2026-07-27) — the
 * upsell-conversion counterpart to `ComputeFeatureAdoptionAction`'s "what's
 * being used." Every row here is a genuine blocked request (§4.x gate
 * checks logging via `PaywallHit::log()`), never a synthetic estimate.
 */
class ComputePaywallHitsSnapshotAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return [
            'total' => PaywallHit::query()->count(),
            'last_30_days' => PaywallHit::query()->where('occurred_at', '>=', now()->subDays(30))->count(),
            'by_limit_key' => $this->byLimitKey(),
            'top_teams' => $this->topRepeatHittingTeams(),
        ];
    }

    /**
     * @return array<int, array{limit_key: string, count: int, teams: int}>
     */
    private function byLimitKey(): array
    {
        return DB::table('paywall_hits')
            ->selectRaw('limit_key, count(*) as aggregate, count(distinct team_id) as team_count')
            ->groupBy('limit_key')
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn ($row) => [
                'limit_key' => (string) $row->limit_key,
                'count' => (int) $row->aggregate,
                'teams' => (int) $row->team_count,
            ])
            ->all();
    }

    /**
     * The teams hitting a paywall most often in the last 30 days — the
     * clearest "reach out and sell them the upgrade" list this dashboard
     * can produce, since a repeat hit means the gated feature is wanted,
     * not just glanced at.
     *
     * @return array<int, array{team_id: int, team_name: string, plan_key: string|null, hits: int, last_hit_at: string}>
     */
    private function topRepeatHittingTeams(): array
    {
        $rows = DB::table('paywall_hits')
            ->where('occurred_at', '>=', now()->subDays(30))
            ->selectRaw('team_id, plan_key, count(*) as aggregate, max(occurred_at) as last_hit_at')
            ->groupBy('team_id', 'plan_key')
            ->orderByDesc('aggregate')
            ->limit(10)
            ->get();

        $teamNames = DB::table('teams')->whereIn('id', $rows->pluck('team_id'))->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'team_id' => (int) $row->team_id,
            'team_name' => (string) ($teamNames[$row->team_id] ?? "Team #{$row->team_id}"),
            'plan_key' => $row->plan_key,
            'hits' => (int) $row->aggregate,
            'last_hit_at' => (string) $row->last_hit_at,
        ])->all();
    }
}
