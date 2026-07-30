<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class NewsletterConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $unsubscribeToken,
    ) {}

    public function build(): self
    {
        return $this->subject("You're subscribed to StockBeat updates")
            ->view('emails.newsletter-confirmation')
            ->with([
                'unsubscribeUrl' => URL::signedRoute('newsletter.unsubscribe', ['token' => $this->unsubscribeToken]),
            ]);
    }
}
