<?php

namespace App\Actions\Billing;

use App\Models\SmsLedger;
use App\Models\Team;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Closes a real, previously-flagged gap: `SmsLedger::REASON_MONTHLY_GRANT`
 * existed as a reason code since 2026-07-16 but nothing ever created a row
 * with it, so every team's SMS wallet sat at `0` forever regardless of
 * plan (confirmed live on Free through Premium alike — this was never a
 * Free-tier-only issue).
 *
 * Idempotent per calendar month per team, matching the same calendar-month
 * convention already used for AI questions (`AiUsageLedger::questionsUsedThisMonth`)
 * and email (`Notification::emailsSentThisMonth`) rather than a per-team
 * billing-anniversary — simplest to reason about, consistent with the rest
 * of the ledger/usage code, and safe to call from multiple trigger points
 * (trial start, a RevenueCat purchase/renewal event, and a daily
 * reconciliation job) without double-granting.
 *
 * Deliberate scope decision, same posture as `AiUsageLedger`'s own
 * documented simplification: this *adds* the plan's allotment to the
 * existing wallet balance rather than maintaining separate "monthly" vs.
 * "topup" buckets, so in practice an unused monthly credit is not strictly
 * prevented from carrying into next month's balance the way Plan §5's copy
 * describes. Getting that exactly right needs bucket-separated ledger
 * accounting, which isn't built (same acknowledged gap `AiUsageLedger`'s
 * docblock calls out for its own bonus grant) — not worth building ahead
 * of real usage data justifying it. Closing "the wallet is always zero" is
 * the priority; exact non-rollover enforcement is a follow-up if it ever
 * matters in practice.
 */
class GrantMonthlySmsCreditsAction
{
    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * @return bool true if a grant was actually created, false if skipped
     *              (no allotment on this plan, or already granted this
     *              calendar month) — lets callers report an accurate count
     *              rather than just "teams checked."
     */
    public function handle(Team $team): bool
    {
        try {
            $allotment = $this->resolveEntitlements->handle($team)['limits']['sms_monthly'] ?? null;
        } catch (ModelNotFoundException) {
            // `ResolveEntitlementsAction` throws if the team's resolved plan
            // key has no matching `plans` row (an unseeded/misconfigured
            // environment) — this is called from account signup
            // (`GrantTrialSubscriptionAction`), which must never hard-fail
            // over a missing SMS grant. Nothing to grant if the plan itself
            // can't be resolved; the daily reconciliation command will pick
            // it up once plans exist.
            return false;
        }

        if ($allotment === null || $allotment <= 0) {
            return false;
        }

        $alreadyGrantedThisMonth = SmsLedger::query()
            ->where('team_id', $team->id)
            ->where('reason', SmsLedger::REASON_MONTHLY_GRANT)
            ->where('created_at', '>=', now()->startOfMonth())
            ->exists();

        if ($alreadyGrantedThisMonth) {
            return false;
        }

        SmsLedger::query()->create([
            'team_id' => $team->id,
            'delta' => $allotment,
            'reason' => SmsLedger::REASON_MONTHLY_GRANT,
            'balance_after' => SmsLedger::currentBalance($team->id) + $allotment,
        ]);

        return true;
    }
}
