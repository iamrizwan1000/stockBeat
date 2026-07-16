<?php

namespace App\Actions\Billing;

use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Subscription;
use App\Models\Team;

/**
 * Grants the app-level 7-day full-Pro trial on account creation (Plan §6.3).
 * The trial length is admin-editable (`plan_limits.trial_days` on the Pro
 * plan), never hardcoded, per §5's "all numeric limits are NOT hardcoded".
 */
class GrantTrialSubscriptionAction
{
    private const DEFAULT_TRIAL_DAYS = 7;

    public function handle(Team $team): Subscription
    {
        $trialDaysLimit = PlanLimit::query()
            ->whereHas('plan', fn ($query) => $query->where('key', Plan::PRO))
            ->where('key', PlanLimit::TRIAL_DAYS)
            ->first();

        $trialDays = $trialDaysLimit === null ? self::DEFAULT_TRIAL_DAYS : $trialDaysLimit->value;

        return Subscription::query()->create([
            'team_id' => $team->id,
            'status' => Subscription::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays((int) $trialDays),
        ]);
    }
}
