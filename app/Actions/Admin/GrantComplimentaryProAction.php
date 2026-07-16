<?php

namespace App\Actions\Admin;

use App\Actions\Billing\ReverseDowngradeFreezeAction;
use App\Models\AdminUser;
use App\Models\Subscription;
use App\Models\Team;

/**
 * Plan §8.7.2: "grant complimentary Pro (with expiry)" — a server-side comp,
 * no store involvement (§8.7.4). `provider = 'comp'` distinguishes it from
 * real Apple/Google subscriptions once the RevenueCat webhook exists.
 */
class GrantComplimentaryProAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
        private readonly ReverseDowngradeFreezeAction $reverseFreeze,
    ) {}

    public function handle(AdminUser $admin, Team $team, int $days): Subscription
    {
        $existing = $team->subscription;
        $before = $existing === null ? null : [
            'status' => $existing->status,
            'expires_at' => $existing->expires_at?->toIso8601String(),
        ];

        $subscription = Subscription::query()->updateOrCreate(
            ['team_id' => $team->id],
            [
                'status' => Subscription::STATUS_ACTIVE,
                'provider' => 'comp',
                'expires_at' => now()->addDays($days),
            ],
        );

        // A comp is a real re-upgrade (Plan §6.4 "springs back on upgrade")
        // — same as an organic RevenueCat purchase reactivating a lapsed team.
        $this->reverseFreeze->handle($team);

        $this->auditLog->handle($admin, 'customer.grant_complimentary_pro', Team::class, $team->id, $before, [
            'status' => $subscription->status,
            'expires_at' => $subscription->expires_at->toIso8601String(),
        ]);

        return $subscription;
    }
}
