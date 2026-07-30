<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A direct, one-to-one admin note about an account action (e.g. a bonus
 * credit grant) — unlike `BroadcastMail`, this isn't marketing, so there's
 * no unsubscribe link or open-tracking pixel; it always sends regardless of
 * the recipient's `marketing_opt_in`/`email_enabled` preferences.
 */
class AdminCreditGrantMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
    ) {}

    public function build(): self
    {
        return $this->subject($this->title)
            ->view('emails.admin-credit-grant');
    }
}
