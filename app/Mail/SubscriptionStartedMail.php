<?php

namespace App\Mail;

use App\Actions\Billing\SendSubscriptionStartedNotificationAction as Reason;
use App\Mail\Concerns\SendsFromModuleAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SubscriptionStartedMail extends Mailable implements ShouldQueue
{
    use Queueable, SendsFromModuleAddress, SerializesModels;

    /**
     * @param  string  $reason  One of `SendSubscriptionStartedNotificationAction`'s
     *                          `REASON_*` constants — see that class for why one
     *                          mail covers a new purchase, a tier change, a
     *                          recovered payment, and a reactivation.
     */
    public function __construct(
        public readonly string $planName,
        public readonly string $reason = Reason::REASON_NEW,
    ) {}

    public function build(): self
    {
        $subject = match ($this->reason) {
            Reason::REASON_PLAN_CHANGE => "You're now on StockBeat {$this->planName}",
            Reason::REASON_PAYMENT_RECOVERED => 'Your StockBeat payment went through',
            Reason::REASON_REACTIVATED => "Welcome back to StockBeat {$this->planName}",
            default => "Your StockBeat {$this->planName} subscription is active",
        };

        return $this->fromModule('billing')->subject($subject)->view('emails.subscription-started', [
            'planName' => $this->planName,
            'reason' => $this->reason,
        ]);
    }
}
