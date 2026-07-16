<?php

namespace App\Actions\Admin\Messaging;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

/**
 * Interpolates the fixed variable set from Plan §8.7.5 ({first_name},
 * {plan}, {trial_days_left}) into broadcast title/body text for a specific
 * recipient. Used both by the real send (per recipient) and by "send test
 * to self" (rendered against the requesting admin's own placeholder data).
 */
class RenderBroadcastTemplateAction
{
    public function handle(string $text, User $user): string
    {
        $subscription = $user->ownedTeam?->subscription;

        return strtr($text, [
            '{first_name}' => $this->firstName($user->name),
            '{plan}' => ucfirst($subscription?->effectivePlanKey() ?? Plan::FREE),
            '{trial_days_left}' => $this->trialDaysLeft($subscription),
        ]);
    }

    private function firstName(string $name): string
    {
        return trim(explode(' ', $name)[0]) ?: $name;
    }

    private function trialDaysLeft(?Subscription $subscription): string
    {
        if ($subscription === null
            || $subscription->status !== Subscription::STATUS_TRIAL
            || $subscription->trial_ends_at === null
            || ! $subscription->trial_ends_at->isFuture()
        ) {
            return '';
        }

        return (string) max(0, (int) ceil(now()->diffInHours($subscription->trial_ends_at) / 24));
    }
}
