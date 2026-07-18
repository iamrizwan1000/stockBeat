<?php

namespace App\Actions\Admin;

use App\Models\OpsHealthSnapshot;
use App\Models\Order;
use App\Models\StoreConnection;
use Illuminate\Support\Carbon;

/**
 * Writes one `ops_health_snapshots` row per day (Plan §8.7.7 gap #3),
 * called by the `ops:record-daily-snapshot` scheduled command. Reuses
 * `GetOpsHealthSnapshotAction`'s own live figures for `failed_jobs_total`/
 * `sms_cost_total` and `ComputeDashboardKpisAction`'s billing math for
 * `mrr`/`churned_teams` rather than recomputing either — same
 * "one calculation, several callers agree" reasoning already used by
 * `DetectAccountAbuseSignalsAction::highSmsCostTeams()`.
 *
 * Metric choices (6 of the "5-8" suggested in the brief, picked from what
 * the two reused actions already compute):
 *  - `active_teams` — distinct teams with a currently-active store
 *    connection (the same `StoreConnection::STATUS_ACTIVE` this module
 *    already tracks elsewhere).
 *  - `mrr` / `churned_teams` — straight from
 *    `ComputeDashboardKpisAction::handle()['subscriptions']`.
 *  - `total_orders_synced` — cumulative `orders` row count (a gauge, not a
 *    delta; the 30-day trend still shows the sync growth rate).
 *  - `failed_jobs_total` / `sms_cost_total` — straight from
 *    `GetOpsHealthSnapshotAction`'s own `failed_jobs.total`/
 *    `sms.consumed_this_month` (the latter resets month-to-month by
 *    design, same sawtooth shape as the live figure it mirrors).
 */
class RecordOpsHealthSnapshotAction
{
    public function __construct(
        private readonly GetOpsHealthSnapshotAction $getOpsHealthSnapshot,
        private readonly ComputeDashboardKpisAction $computeDashboardKpis,
    ) {}

    public function handle(?Carbon $date = null): OpsHealthSnapshot
    {
        $date ??= now();

        $health = $this->getOpsHealthSnapshot->handle();
        $kpis = $this->computeDashboardKpis->handle();

        $activeTeams = StoreConnection::query()
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->distinct('team_id')
            ->count('team_id');

        return OpsHealthSnapshot::query()->updateOrCreate(
            ['date' => $date->copy()->startOfDay()],
            [
                'active_teams' => $activeTeams,
                'mrr' => $kpis['subscriptions']['mrr'],
                'churned_teams' => $kpis['subscriptions']['cancellations_this_month'],
                'total_orders_synced' => Order::query()->count(),
                'failed_jobs_total' => $health['failed_jobs']['total'],
                'sms_cost_total' => $health['sms']['consumed_this_month'],
            ],
        );
    }
}
