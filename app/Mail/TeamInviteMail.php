<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TeamInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $teamName,
        public readonly string $inviterName,
        public readonly string $role,
    ) {}

    public function build(): self
    {
        return $this->subject("{$this->inviterName} invited you to join {$this->teamName} on StockBeat")
            ->view('emails.team-invite');
    }
}
