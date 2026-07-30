<?php

namespace App\Actions\Marketing;

use App\Mail\NewsletterConfirmationMail;
use App\Models\NewsletterSubscriber;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Public newsletter signup — upserts by email so re-subscribing (including
 * after a previous unsubscribe) just clears `unsubscribed_at` rather than
 * creating a duplicate row or erroring.
 *
 * The confirmation email is only sent when this call actually changes
 * something (a genuine new subscribe or a re-subscribe-after-unsubscribe)
 * — computed *before* mutating the row, so a double-tap on an already-active
 * subscription is a silent no-email no-op rather than sending a second
 * confirmation email for nothing.
 */
class SubscribeToNewsletterAction
{
    public function handle(string $email): NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::query()->firstOrNew(['email' => $email]);
        $wasAlreadyActive = $subscriber->exists && $subscriber->unsubscribed_at === null;

        if (! $subscriber->exists) {
            $subscriber->unsubscribe_token = Str::random(40);
        }

        $subscriber->subscribed_at = now();
        $subscriber->unsubscribed_at = null;
        $subscriber->save();

        if (! $wasAlreadyActive) {
            Mail::to($email)->queue(new NewsletterConfirmationMail($subscriber->unsubscribe_token));
        }

        return $subscriber;
    }
}
