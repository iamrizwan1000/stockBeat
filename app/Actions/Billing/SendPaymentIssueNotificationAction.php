<?php

namespace App\Actions\Billing;

use App\Actions\Notifications\SendPushNotificationAction;
use App\Mail\SubscriptionPaymentIssueMail;
use App\Models\Notification;
use App\Models\Team;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the seller their payment failed and they're now in the grace period
 * (`BILLING_ISSUE` -> `Subscription::STATUS_GRACE`, Plan §6.1). This closes
 * the most costly of the three gaps this file's siblings address: before it,
 * a declined card put a paying customer on a silent countdown to losing
 * their stores, with no message at any point.
 *
 * The fix isn't in our app — the payment method lives with whichever store
 * billed them — so the copy names Apple or Google specifically rather than
 * pointing at a StockBeat screen that can't change a card.
 *
 * `bypassPreferences: true` and `PRIORITY_HIGH`, following
 * `NotifyCustomerOfCreditGrantAction`'s precedent for a direct,
 * account-specific notice: this is action-required and time-limited, so it
 * shouldn't wait out quiet hours or be swallowed by a push-muted preference
 * meant for rule alerts.
 */
class SendPaymentIssueNotificationAction
{
    public function __construct(
        private readonly SendPushNotificationAction $sendPush,
    ) {}

    public function handle(Team $team, ?string $provider): void
    {
        $owner = $team->owner;

        if ($owner === null) {
            return;
        }

        $this->sendPush->handle(
            $owner,
            'Payment didn\'t go through',
            'Your subscription is still active while it\'s retried — update your payment method to avoid losing access.',
            [],
            Notification::TYPE_SUBSCRIPTION_PAYMENT_ISSUE,
            true,
            null,
            null,
            null,
            Notification::PRIORITY_HIGH,
            true,
        );

        Mail::to($owner->email)->queue(new SubscriptionPaymentIssueMail($provider));
    }
}
