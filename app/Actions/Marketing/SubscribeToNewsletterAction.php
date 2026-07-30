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
 */
class SubscribeToNewsletterAction
{
    public function handle(string $email): NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::query()->firstOrNew(['email' => $email]);

        if (! $subscriber->exists) {
            $subscriber->unsubscribe_token = Str::random(40);
        }

        $subscriber->subscribed_at = now();
        $subscriber->unsubscribed_at = null;
        $subscriber->save();

        Mail::to($email)->queue(new NewsletterConfirmationMail($subscriber->unsubscribe_token));

        return $subscriber;
    }
}
