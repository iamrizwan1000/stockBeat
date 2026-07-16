<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Outbound customer-inbox message (Plan §4.5): sent from our own domain
 * with a plus-addressed Reply-To so the customer's reply threads straight
 * back in (`WebhookController::emailInbound`), without exposing the
 * merchant's or our support inbox's real address.
 */
class InboxMessageMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $body,
        public readonly string $replyToAddress,
    ) {}

    public function build(): self
    {
        return $this->subject('New message from the seller')
            ->replyTo($this->replyToAddress)
            ->view('emails.inbox-message');
    }
}
