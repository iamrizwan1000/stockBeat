<?php

namespace App\Actions\Admin;

use App\Actions\Billing\ReverseDowngradeFreezeAction;
use App\Models\AdminUser;
use App\Models\Subscription;
use App\Models\Team;

/**
 * Plan §8.7.2: "extend trial (n days)". Extends from the current
 * `trial_ends_at` if it's still in the future, otherwise from now — so
 * granting +7 days to an already-expired trial doesn't backdate it.
 */
class ExtendTrialAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
        private readonly ReverseDowngradeFreezeAction $reverseFreeze,
    ) {}

    public function handle(AdminUser $admin, Team $team, int $days): Subscription
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
