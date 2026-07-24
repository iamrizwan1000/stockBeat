<?php

namespace App\Console\Commands;

use App\Actions\Billing\CheckQuotaWarningsAction;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * Plan §5.1's 80%-quota upsell, checked daily across every entitled team.
 * `CheckQuotaWarningsAction` is idempotent per team per channel per
 * calendar month, so a daily cadence is safe — a team sitting above 80%
 * for the rest of the month is simply skipped on every subsequent run
 * until the next month resets `QuotaWarningNotification::alreadySentThisMonth()`.
 */
class CheckQuotaWarnings extends Command
{
    protected $signature = 'usage:check-quota-warnings';

    protected $description = 'Send the 80%-quota push notification to any team that just crossed it';

    public function handle(CheckQuotaWarningsAction $checkQuotaWarnings): int
    {
        $checked = 0;
        $sent = 0;

        Subscription::query()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_GRACE, Subscription::STATUS_TRIAL])
            ->with('team.owner')
            ->chunkById(100, function ($subscriptions) use ($checkQuotaWarnings, &$checked, &$sent) {
                foreach ($subscriptions as $subscription) {
                    if (! $subscription->isEntitled() || $subscription->team === null) {
                        continue;
                    }

                    $checked++;
                    $sent += $checkQuotaWarnings->handle($subscription->team);
                }
            });

        $this->info("Checked {$checked} entitled team(s), sent {$sent} quota warning(s).");

        return self::SUCCESS;
    }
}
