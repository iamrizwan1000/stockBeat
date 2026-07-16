<?php

namespace App\Console\Commands;

use App\Actions\Billing\ApplyDowngradeFreezeAction;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * The moment `trial_ends_at` passes (Plan §6.4). Entitlements already
 * self-correct to Free the instant `trial_ends_at` is in the past —
 * `Subscription::effectivePlanKey()` is computed live on every read, no
 * job needed for that part. This command exists for the two things that
 * *aren't* already dynamic: flipping `status` to `expired` (so admin
 * reporting — e.g. "active trials" on the KPI dashboard — doesn't keep
 * counting a lapsed trial forever) and actually running the downgrade
 * freeze side effects (pausing stores, disabling rules, suspending seats),
 * which are one-time state mutations, not something a live read can do.
 */
class ExpireLapsedTrials extends Command
{
    protected $signature = 'subscriptions:expire-trials';

    protected $description = 'Flip lapsed trials to expired and apply the downgrade freeze';

    public function handle(ApplyDowngradeFreezeAction $applyFreeze): int
    {
        $expired = 0;

        Subscription::query()
            ->where('status', Subscription::STATUS_TRIAL)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->with('team')
            ->chunkById(100, function ($subscriptions) use ($applyFreeze, &$expired) {
                foreach ($subscriptions as $subscription) {
                    $subscription->update(['status' => Subscription::STATUS_EXPIRED]);
                    $applyFreeze->handle($subscription->team);
                    $expired++;
                }
            });

        $this->info("Expired {$expired} lapsed trial(s).");

        return self::SUCCESS;
    }
}
