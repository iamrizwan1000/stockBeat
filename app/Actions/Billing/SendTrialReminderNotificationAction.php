<?php

namespace App\Actions\Billing;

use App\Actions\Notifications\SendPushNotificationAction;
use App\Mail\TrialEndingMail;
use App\Models\Notification;
use App\Models\Subscription;
use Illuminate\Support\Facades\Mail;

/**
 * Plan §6.3: "push + email on day 5 and day 7" of the 7-day trial. Sent to
 * the team owner (the only person who ever sees billing state today — no
 * unified inbox/per-member billing visibility exists).
 */
class SendTrialReminderNotificationAction
{
    public function __construct(
        private readonly SendPushNotificationAction $sendPush,
    ) {}

    public function handle(Subscription $subscription, int $daysRemaining): void
    {
        $owner = $subscription->team->owner;

        $title = $daysRemaining <= 0 ? 'Your trial ends today' : "{$daysRemaining} day".($daysRemaining === 1 ? '' : 's').' left on your trial';
        $body = $daysRemaining <= 0
            ? 'Upgrade now to keep everything you\'ve set up active.'
            : 'Upgrade any time to keep every store, rule, and day of history active.';

        $this->sendPush->handle($owner, $title, $body, ['trial_days_remaining' => (string) $daysRemaining], Notification::TYPE_TRIAL_REMINDER);

        Mail::to($owner->email)->queue(new TrialEndingMail($daysRemaining));
    }
}
