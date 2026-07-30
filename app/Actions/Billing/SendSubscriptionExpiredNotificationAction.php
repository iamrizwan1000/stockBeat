<?php

namespace App\Actions\Billing;

use App\Actions\Notifications\SendPushNotificationAction;
use App\Mail\SubscriptionExpiredMail;
use App\Models\Notification;
use App\Models\Team;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the seller their subscription lapsed and what that actually changed
 * (`EXPIRATION`, Plan §6.4). Fired alongside `ApplyDowngradeFreezeAction`,
 * so the copy states the real consequences that action just applied —
 * extra stores paused, custom rules disabled, extra seats suspended — rather
 * than a vague "your plan changed" that leaves the seller to discover a
 * silent store pause on their own.
 *
 * It also says plainly that nothing was deleted and resubscribing restores
 * everything, which is true and verifiable: every mutation
 * `ApplyDowngradeFreezeAction` makes is reversible by
 * `ReverseDowngradeFreezeAction`, which restores only what the freeze itself
 * touched.
 *
 * `bypassPreferences: true` and `PRIORITY_HIGH`, same reasoning as
 * `SendPaymentIssueNotificationAction` — the seller's stores just stopped
 * syncing, so this must not wait out quiet hours.
 */
class SendSubscriptionExpiredNotificationAction
{
    public function __construct(
        private readonly SendPushNotificationAction $sendPush,
    ) {}

    public function handle(Team $team): void
    {
        $owner = $team->owner;

        if ($owner === null) {
            return;
        }

        $this->sendPush->handle(
            $owner,
            'Your subscription has ended',
            'Extra stores are paused and custom rules are off. Nothing was deleted — resubscribe to switch it all back on.',
            [],
            Notification::TYPE_SUBSCRIPTION_EXPIRED,
            true,
            null,
            null,
            null,
            Notification::PRIORITY_HIGH,
            true,
        );

        Mail::to($owner->email)->queue(new SubscriptionExpiredMail);
    }
}
