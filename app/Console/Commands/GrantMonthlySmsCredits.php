<?php

namespace App\Console\Commands;

use App\Actions\Billing\GrantMonthlySmsCreditsAction;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * Daily reconciliation safety net for `GrantMonthlySmsCreditsAction` — the
 * action already fires immediately from `GrantTrialSubscriptionAction`
 * (trial start) and `ProcessRevenueCatEventAction` (purchase/renewal), same
 * "real-time trigger + periodic reconciliation" posture as every webhook-
 * backed sync elsewhere in this app (`orders:poll-woo` etc.). This command
 * exists to catch anything those two miss — a team whose subscription was
 * activated by some other path, or a calendar-month rollover for a team
 * that hasn't had a purchase/renewal event fire yet this month. The action
 * itself is idempotent per team per calendar month, so running this daily
 * (rather than only on the 1st) is safe and self-healing.
 */
class GrantMonthlySmsCredits extends Command
{
    protected $signature = 'sms:grant-monthly-credits';

    protected $description = "Grant each entitled team's monthly SMS allotment, once per calendar month";

    public function handle(GrantMonthlySmsCreditsAction $grantCredits): int
    {
        $checked = 0;
        $granted = 0;

        Subscription::query()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_GRACE, Subscription::STATUS_TRIAL])
            ->with('team')
            ->chunkById(100, function ($subscriptions) use ($grantCredits, &$checked, &$granted) {
                foreach ($subscriptions as $subscription) {
                    if (! $subscription->isEntitled() || $subscription->team === null) {
                        continue;
                    }

                    $checked++;

                    if ($grantCredits->handle($subscription->team)) {
                        $granted++;
                    }
                }
            });

        $this->info("Checked {$checked} entitled team(s), granted {$granted} new monthly SMS credit(s).");

        return self::SUCCESS;
    }
}
