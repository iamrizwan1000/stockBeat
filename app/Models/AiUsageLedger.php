<?php

namespace App\Models;

use Database\Factories\AiUsageLedgerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Same shape as `SmsLedger` (Plan §9): a running per-team debit log for
 * every AI question asked. Quota is enforced by counting this **calendar
 * month's** `question` debits against an *effective* limit — the plan's
 * `ai_questions_monthly` plus any `topup_iap`-reason bonus credited this
 * same calendar month (`effectiveMonthlyLimit()`) — rather than a separate
 * monthly-grant job. `topup_iap` is used both for the still-not-built IAP
 * IAP top-up-pack purchase flow (Plan §5) and, since 2026-07-22, for
 * admin-granted bonus credits (`GrantBonusAiCreditsAction`, mirrors
 * `GrantBonusSmsCreditsAction`'s use of `SmsLedger::REASON_TOPUP_IAP` for
 * the same admin-comp purpose).
 *
 * Deliberate scope simplification, documented rather than hidden: a bonus
 * grant only raises *that calendar month's* cap — it doesn't carry into
 * next month as a true non-expiring wallet would. A real rollover wallet
 * needs bucket-separated accounting (monthly allotment vs. never-expiring
 * top-up balance), which neither this ledger nor `SmsLedger` actually
 * implements yet (`SmsLedger::REASON_MONTHLY_GRANT` is itself still never
 * dispatched anywhere in the app) — not worth building ahead of real
 * top-up-pack usage data justifying it.
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
class AiUsageLedger extends Model
{
    /** @use HasFactory<AiUsageLedgerFactory> */
    use HasFactory;

    public const REASON_MONTHLY_GRANT = 'monthly_grant';

    public const REASON_TOPUP_IAP = 'topup_iap';

    public const REASON_QUESTION = 'question';

    public const REASON_FREEZE = 'freeze';

    protected $table = 'ai_usage_ledger';

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

    public static function questionsUsedThisMonth(int $teamId): int
    {
        return (int) static::query()
            ->where('team_id', $teamId)
            ->where('reason', self::REASON_QUESTION)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * Daily question counts for the last `$days` days (today inclusive),
     * keyed by `Y-m-d` — same shape/purpose as `SmsLedger::dailySendCounts()`.
     *
     * @return array<string, int>
     */
    public static function dailyQuestionCounts(int $teamId, int $days): array
    {
        return static::query()
            ->where('team_id', $teamId)
            ->where('reason', self::REASON_QUESTION)
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->groupBy('day')
            ->pluck('count', 'day')
            ->map(fn ($count) => (int) $count)
            ->all();
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
