<?php

namespace App\Actions\Admin;

use App\Actions\Billing\ReverseDowngradeFreezeAction;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Team;
use App\Support\Concurrency\IdempotencyGuard;

/**
 * Plan §8.7.2: "extend trial (n days)". Extends from the current
 * `trial_ends_at` if it's still in the future, otherwise from now — so
 * granting +7 days to an already-expired trial doesn't backdate it.
 *
 * Always sets `plan_key` to Premium, same as the original trial grant
 * (`GrantTrialSubscriptionAction`) — "full-featured trial taken literally"
 * per Plan §6.3. Two real gaps this closes (found 2026-07-28, previously
 * this action never touched `plan_key` at all): a team with no subscription
 * row yet would get a fresh row with `plan_key: null`, resolving to Free
 * entitlements despite `status: trial`; and reviving an expired *paid*
 * subscription (e.g. a lapsed Starter/Pro team) would leave the old,
 * lower `plan_key` in place, silently granting only that tier's limits
 * during what's supposed to be a full-featured trial.
 */
class ExtendTrialAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
        private readonly ReverseDowngradeFreezeAction $reverseFreeze,
    ) {}

    /**
     * Guarded against an admin double-clicking "Extend" on a slow connection:
     * the extension is cumulative (it adds to a still-future `trial_ends_at`),
     * so two rapid calls genuinely hand out twice the days asked for — the
     * same real double-grant shape as `GrantBonusSmsCreditsAction` et al.
     */
    public function handle(AdminUser $admin, Team $team, int $days): ?Subscription
    {
        return IdempotencyGuard::once("extend-trial:{$team->id}", 10, fn () => $this->extend($admin, $team, $days));
    }

    private function extend(AdminUser $admin, Team $team, int $days): Subscription
    {
        $subscription = $team->subscription;
        $wasExpired = $subscription?->status === Subscription::STATUS_EXPIRED;
        $before = $subscription === null ? null : [
            'status' => $subscription->status,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
        ];

        $baseline = ($subscription?->trial_ends_at !== null && $subscription->trial_ends_at->isFuture())
            ? $subscription->trial_ends_at
            : now();

        $subscription = Subscription::query()->updateOrCreate(
            ['team_id' => $team->id],
            [
                'status' => Subscription::STATUS_TRIAL,
                'plan_key' => Plan::PREMIUM,
                'trial_ends_at' => $baseline->clone()->addDays($days),
            ],
        );

        // Reviving a lapsed trial is a re-upgrade too (Plan §6.4).
        if ($wasExpired) {
            $this->reverseFreeze->handle($team);
        }

        $this->auditLog->handle($admin, 'customer.extend_trial', Team::class, $team->id, $before, [
            'status' => $subscription->status,
            'trial_ends_at' => $subscription->trial_ends_at->toIso8601String(),
        ]);

        return $subscription;
    }
}
