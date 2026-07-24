<?php

namespace App\Models;

use Database\Factories\SmsLedgerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $delta
 * @property string $reason
 * @property int $balance_after
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'delta', 'reason', 'balance_after', 'meta'])]
class SmsLedger extends Model
{
    /** @use HasFactory<SmsLedgerFactory> */
    use HasFactory;

    public const REASON_MONTHLY_GRANT = 'monthly_grant';

    public const REASON_TOPUP_IAP = 'topup_iap';

    public const REASON_SEND = 'send';

    public const REASON_FREEZE = 'freeze';

    protected $table = 'sms_ledger';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public static function currentBalance(int $teamId): int
    {
        return (int) (static::query()->where('team_id', $teamId)->latest('id')->value('balance_after') ?? 0);
    }

    public static function sentThisMonth(int $teamId): int
    {
        return (int) static::query()
            ->where('team_id', $teamId)
            ->where('reason', self::REASON_SEND)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * Daily send counts for the last `$days` days (today inclusive), keyed
     * by `Y-m-d` — used to render a usage-history graph. Days with zero
     * sends are simply absent; the caller fills gaps to build a continuous
     * series (`GetUsageSummaryAction::fillDailySeries()`).
     *
     * @return array<string, int>
     */
    public static function dailySendCounts(int $teamId, int $days): array
    {
        return static::query()
            ->where('team_id', $teamId)
            ->where('reason', self::REASON_SEND)
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->groupBy('day')
            ->pluck('count', 'day')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
