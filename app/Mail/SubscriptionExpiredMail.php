<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromModuleAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SubscriptionExpiredMail extends Mailable implements ShouldQueue
{
    use Queueable, SendsFromModuleAddress, SerializesModels;

    public function build(): self
    {
        return $this->fromModule('billing')
            ->subject('Your StockBeat subscription has ended')
            ->view('emails.subscription-expired');
    }
}
