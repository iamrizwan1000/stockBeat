<?php

namespace App\Models;

use Database\Factories\EmailUsageLedgerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Same shape as `SmsLedger`/`AiUsageLedger`, but narrower in purpose: email
 * usage itself stays live-counted via `Notification::emailsSentThisMonth()`
 * (there's no per-send debit here) — this ledger exists only to track an
 * admin-granted bonus (`topup_iap` reason, via `GrantBonusEmailCreditsAction`)
 * that raises the *current calendar month's* effective `email_monthly` cap,
 * the same way `AiUsageLedger::effectiveMonthlyLimit()` nets a bonus into
 * the AI question cap. Same deliberate scope simplification as SMS/AI: a
 * bonus only raises this month's cap, it doesn't carry into next month.
 *
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
class EmailUsageLedger extends Model
{
    /** @use HasFactory<EmailUsageLedgerFactory> */
    use HasFactory;

    public const REASON_MONTHLY_GRANT = 'monthly_grant';

    public const REASON_TOPUP_IAP = 'topup_iap';

    public const REASON_FREEZE = 'freeze';

    protected $table = 'email_usage_ledger';

    /**
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

    public static function bonusGrantedThisMonth(int $teamId): int
    {
        return (int) static::query()
            ->where('team_id', $teamId)
            ->where('reason', self::REASON_TOPUP_IAP)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('delta');
    }

    /**
     * `$planLimit` of `null` means unlimited — stays `null` regardless of
     * any bonus grant, since there's no cap to raise.
     */
    public static function effectiveMonthlyLimit(int $teamId, ?int $planLimit): ?int
    {
        if ($planLimit === null) {
            return null;
        }

        return $planLimit + self::bonusGrantedThisMonth($teamId);
    }
}
