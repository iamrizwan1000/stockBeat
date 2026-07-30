<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromModuleAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * `$provider` is the `subscriptions.provider` value (`apple`/`google`, or
 * null when unknown) — the payment method lives with the store that billed
 * them, not with us, so the fix instruction has to name the right one
 * instead of pointing at a StockBeat screen that can't change a card.
 */
class SubscriptionPaymentIssueMail extends Mailable implements ShouldQueue
{
    use Queueable, SendsFromModuleAddress, SerializesModels;

    public function __construct(
        public readonly ?string $provider,
    ) {}

    public function build(): self
    {
        return $this->fromModule('billing')
            ->subject('Action needed: your StockBeat payment didn\'t go through')
            ->view('emails.subscription-payment-issue', ['provider' => $this->provider]);
    }
}
