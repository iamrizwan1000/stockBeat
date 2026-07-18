<?php

namespace App\Models;

use Database\Factories\OpsHealthSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per calendar day of a handful of trend-worthy Ops & Health
 * scalars (Plan §8.7.7 gap #3 — "no historical trending, every metric is
 * current-state only"), written daily by `RecordOpsHealthSnapshotAction`
 * via the `ops:record-daily-snapshot` scheduled command — same
 * "pre-aggregate once, read the rollup" shape as `DailyStat`/
 * `analytics:aggregate-daily`, just team-agnostic (one row for the whole
 * app per day, not per team/connection).
 *
 * @property int $id
 * @property Carbon $date
 * @property int $active_teams
 * @property float $mrr
 * @property int $churned_teams
 * @property int $total_orders_synced
 * @property int $failed_jobs_total
 * @property int $sms_cost_total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['date', 'active_teams', 'mrr', 'churned_teams', 'total_orders_synced', 'failed_jobs_total', 'sms_cost_total'])]
class OpsHealthSnapshot extends Model
{
    /** @use HasFactory<OpsHealthSnapshotFactory> */
    use HasFactory;

    protected $table = 'ops_health_snapshots';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'mrr' => 'float',
        ];
    }
}
