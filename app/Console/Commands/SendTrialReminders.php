<?php

namespace App\Console\Commands;

use App\Actions\Billing\SendTrialReminderNotificationAction;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * Plan §6.3 day-5/day-7 trial reminders. Runs hourly; the two `*_sent_at`
 * guard columns on `subscriptions` are what stop a reminder firing twice —
 * matching `SendMorningDigests`'s per-team `last_digest_sent_at` pattern.
 * "Day 5"/"day 7" are 2-days-remaining and 0-days-remaining thresholds
 * rather than literal calendar days, so this still makes sense if an admin
 * ever tunes `plan_limits.trial_days` away from the default 7.
 */
class SendTrialReminders extends Command
{
    protected $signature = 'trials:send-reminders';

    protected $description = 'Send the day-5 and day-7 trial-ending push+email reminders';

    public function handle(SendTrialReminderNotificationAction $action): int
    {
        $sent = 0;

        Subscription::query()
            ->where('status', Subscription::STATUS_TRIAL)
            ->whereNotNull('trial_ends_at')
            ->with('team.owner')
            ->chunkById(100, function ($subscriptions) use ($action, &$sent) {
                foreach ($subscriptions as $subscription) {
                    // floor(), not ceil(): "ends in 2 hours" must land in the
                    // day-7/final bucket (0), not get rounded up into the
                    // day-5/"2 days left" bucket.
                    $daysRemaining = (int) floor(now()->diffInHours($subscription->trial_ends_at, false) / 24);

                    if ($daysRemaining <= 2 && $daysRemaining > 0 && $subscription->trial_reminder_day5_sent_at === null) {
                        $action->handle($subscription, $daysRemaining);
                        $subscription->update(['trial_reminder_day5_sent_at' => now()]);
                        $sent++;

                        continue;
                    }

                    if ($daysRemaining <= 0 && $subscription->trial_reminder_day7_sent_at === null) {
                        $action->handle($subscription, 0);
                        $subscription->update(['trial_reminder_day7_sent_at' => now()]);
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} trial reminder(s).");

        return self::SUCCESS;
    }
}
