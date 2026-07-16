<?php

namespace App\Actions\Billing;

use App\Models\Plan;
use App\Models\Team;

/**
 * Resolves a team's effective plan and limits from its subscription state
 * (Plan §10 `/me`, §6.4). Computed live on every read — trial expiry and
 * downgrades need no scheduled job to "flip" anything.
 */
class ResolveEntitlementsAction
{
    /**
     * @return array{plan: string, limits: array<string, mixed>, subscription_status: ?string, trial_ends_at: ?string}
     */
    public function handle(Team $team): array
    {
        $subscription = $team->subscription;
        $planKey = $subscription?->effectivePlanKey() ?? Plan::FREE;

        $plan = Plan::query()
            ->with('limits')
            ->where('key', $planKey)
            ->firstOrFail();

        return [
            'plan' => $plan->key,
            'limits' => $plan->limitsArray(),
            'subscription_status' => $subscription?->status,
            'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
        ];
    }
}
