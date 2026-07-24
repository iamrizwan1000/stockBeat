<?php

namespace App\Actions\Billing;

use App\Models\AiUsageLedger;
use App\Models\Notification;
use App\Models\SmsLedger;
use App\Models\Team;
use Illuminate\Support\Carbon;

/**
 * Usage-history view over the same three channels `ResolveFullEntitlementsAction`
 * already reports a current-standing balance for (SMS/AI questions/email) —
 * this adds "how much of this month's allotment is used" (`pct_used`,
 * `quota_warning` at 80%+, per Plan §5.1's "quota consumption shown in
 * Settings with an upsell at 80%") and a daily breakdown suitable for a
 * usage graph, neither of which `/me`/`/billing/entitlements` expose.
 *
 * SMS is reported slightly differently from AI/email: its ledger is a
 * running wallet balance (top-ups never expire), not a hard monthly reset,
 * so `balance` (the real spendable balance) and `used_this_month`/
 * `pct_used` (informational, against the plan's monthly allotment) are two
 * separate, both-meaningful numbers — don't conflate them.
 */
class GetUsageSummaryAction
{
    private const DAILY_WINDOW_DAYS = 30;

    private const QUOTA_WARNING_THRESHOLD_PCT = 80.0;

    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Team $team): array
    {
        $limits = $this->resolveEntitlements->handle($team)['limits'];

        return [
            'sms' => $this->smsUsage($team, $limits['sms_monthly'] ?? null),
            'ai_questions' => $this->aiUsage($team, $limits['ai_questions_monthly'] ?? null),
            'emails' => $this->emailUsage($team, $limits['email_monthly'] ?? null),
        ];
    }

    /**
     * @return array{balance: int, plan_monthly_allotment: int|null, used_this_month: int, pct_used: float|null, quota_warning: bool, daily: array<int, array{date: string, count: int}>}
     */
    private function smsUsage(Team $team, ?int $monthlyAllotment): array
    {
        $usedThisMonth = SmsLedger::sentThisMonth($team->id);

        return [
            'balance' => SmsLedger::currentBalance($team->id),
            'plan_monthly_allotment' => $monthlyAllotment,
            'used_this_month' => $usedThisMonth,
            ...$this->quotaFields($usedThisMonth, $monthlyAllotment),
            'daily' => $this->fillDailySeries(SmsLedger::dailySendCounts($team->id, self::DAILY_WINDOW_DAYS)),
        ];
    }

    /**
     * @return array{limit: int|null, used_this_month: int, remaining: int|null, pct_used: float|null, quota_warning: bool, daily: array<int, array{date: string, count: int}>}
     */
    private function aiUsage(Team $team, ?int $monthlyLimit): array
    {
        $effectiveLimit = AiUsageLedger::effectiveMonthlyLimit($team->id, $monthlyLimit);
        $usedThisMonth = AiUsageLedger::questionsUsedThisMonth($team->id);

        return [
            'limit' => $effectiveLimit,
            'used_this_month' => $usedThisMonth,
            'remaining' => $effectiveLimit === null ? null : max($effectiveLimit - $usedThisMonth, 0),
            ...$this->quotaFields($usedThisMonth, $effectiveLimit),
            'daily' => $this->fillDailySeries(AiUsageLedger::dailyQuestionCounts($team->id, self::DAILY_WINDOW_DAYS)),
        ];
    }

    /**
     * @return array{limit: int|null, used_this_month: int, remaining: int|null, pct_used: float|null, quota_warning: bool, daily: array<int, array{date: string, count: int}>}
     */
    private function emailUsage(Team $team, ?int $monthlyLimit): array
    {
        $usedThisMonth = Notification::emailsSentThisMonth($team);

        return [
            'limit' => $monthlyLimit,
            'used_this_month' => $usedThisMonth,
            'remaining' => $monthlyLimit === null ? null : max($monthlyLimit - $usedThisMonth, 0),
            ...$this->quotaFields($usedThisMonth, $monthlyLimit),
            'daily' => $this->fillDailySeries(Notification::dailyEmailCounts($team, self::DAILY_WINDOW_DAYS)),
        ];
    }

    /**
     * @return array{pct_used: float|null, quota_warning: bool}
     */
    private function quotaFields(int $used, ?int $limit): array
    {
        if ($limit === null || $limit <= 0) {
            return ['pct_used' => null, 'quota_warning' => false];
        }

        $pctUsed = round(min($used / $limit, 1.0) * 100, 1);

        return [
            'pct_used' => $pctUsed,
            'quota_warning' => $pctUsed >= self::QUOTA_WARNING_THRESHOLD_PCT,
        ];
    }

    /**
     * Turns a sparse `date => count` map into a continuous, zero-filled
     * series for the last `self::DAILY_WINDOW_DAYS` days — a graph
     * shouldn't have to special-case missing days.
     *
     * @param  array<string, int>  $sparseCounts
     * @return array<int, array{date: string, count: int}>
     */
    private function fillDailySeries(array $sparseCounts): array
    {
        $series = [];
        $cursor = Carbon::now()->subDays(self::DAILY_WINDOW_DAYS - 1)->startOfDay();

        for ($i = 0; $i < self::DAILY_WINDOW_DAYS; $i++) {
            $day = $cursor->copy()->addDays($i)->toDateString();
            $series[] = ['date' => $day, 'count' => $sparseCounts[$day] ?? 0];
        }

        return $series;
    }
}
