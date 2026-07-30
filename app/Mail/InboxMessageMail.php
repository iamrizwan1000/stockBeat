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
 *
 * Deliberately does NOT use `SendsFromModuleAddress`, unlike its siblings:
 * this is the one message whose recipient is the *seller's customer*, not the
 * seller, so the display name has to stay the store's own
 * (`StoreConnection::$store_display_name` — it should read as "Jane's
 * Boutique", not "StockBeat Alerts"). `fromModule()` would overwrite that
 * with the configured sender name. Only the underlying address is sourced
 * from the `notifications` sender here; the branded name and the
 * threading Reply-To are preserved exactly as before.
 */
class InboxMessageMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $body,
        public readonly string $replyToAddress,
        public readonly ?string $fromName = null,
    ) {}

    public function build(): self
    {
        $address = config('mail.senders.notifications.address') ?: config('mail.from.address');
        $fallbackName = config('mail.senders.notifications.name') ?: config('mail.from.name');

        return $this->from($address, $this->fromName ?: $fallbackName)
            ->subject('New message from the seller')
            ->replyTo($this->replyToAddress)
            ->view('emails.inbox-message');
    }
}
