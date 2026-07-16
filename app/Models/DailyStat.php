<?php

namespace App\Models;

use Database\Factories\DailyStatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pre-aggregated per-connection, per-day rollup (Plan §9) that historical
 * (7-day/30-day) analytics reads from instead of re-scanning `orders` —
 * "today" is never read from here since it isn't finalized yet (see
 * `GetAnalyticsSummaryAction`).
 *
 * @property int $id
 * @property int $team_id
 * @property int $connection_id
 * @property Carbon $date
 * @property int $orders_count
 * @property float $revenue
 * @property float|null $revenue_base
 * @property float $aov
 * @property int $refunds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'connection_id', 'date', 'orders_count', 'revenue', 'revenue_base', 'aov', 'refunds'])]
class DailyStat extends Model
{
    /** @use HasFactory<DailyStatFactory> */
    use HasFactory;

    protected $table = 'daily_stats';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'revenue' => 'float',
            'revenue_base' => 'float',
            'aov' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<StoreConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(StoreConnection::class, 'connection_id');
    }
}
