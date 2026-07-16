<?php

namespace App\Actions\Admin;

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

        $this->auditLog->handle($admin, 'customer.grant_complimentary_pro', Team::class, $team->id, $before, [
            'status' => $subscription->status,
            'expires_at' => $subscription->expires_at->toIso8601String(),
        ]);

        return $subscription;
    }
}
