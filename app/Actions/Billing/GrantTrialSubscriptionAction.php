<?php

namespace App\Actions\Billing;

use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Subscription;
use App\Models\Team;

/**
 * Grants the app-level 7-day full-featured trial on account creation (Plan
 * §6.3). "Full-featured" is taken literally now that there are 4 tiers —
 * the trial grants Premium (the top tier), not Pro, so a trialing seller
 * experiences everything (advanced triggers included) before deciding
 * which paid tier actually fits their business. The trial length is
 * admin-editable (`plan_limits.trial_days` on the Premium plan), never
 * hardcoded, per §5's "all numeric limits are NOT hardcoded".
 */
class GrantTrialSubscriptionAction
{
    private const DEFAULT_TRIAL_DAYS = 7;

    public function __construct(
        private readonly GrantMonthlySmsCreditsAction $grantSmsCredits,
    ) {}

    public function handle(Team $team): Subscription
    {
        $trialDaysLimit = PlanLimit::query()
            ->whereHas('plan', fn ($query) => $query->where('key', Plan::PREMIUM))
            ->where('key', PlanLimit::TRIAL_DAYS)
            ->first();

        $trialDays = $trialDaysLimit === null ? self::DEFAULT_TRIAL_DAYS : $trialDaysLimit->value;

        $subscription = Subscription::query()->create([
            'team_id' => $team->id,
            'status' => Subscription::STATUS_TRIAL,
            'plan_key' => Plan::PREMIUM,
            'trial_ends_at' => now()->addDays((int) $trialDays),
        ]);

        // A trialing seller gets Premium's full SMS allotment immediately,
        // not on the next daily reconciliation run (`sms:grant-monthly-credits`).
        $this->grantSmsCredits->handle($team);

        return $subscription;
    }
}
