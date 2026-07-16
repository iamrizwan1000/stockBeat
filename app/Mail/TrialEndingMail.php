<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TrialEndingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $daysRemaining,
    ) {}

    public function build(): self
    {
        $subject = $this->daysRemaining <= 0
            ? 'Your StockBeat trial ends today'
            : "Your StockBeat trial ends in {$this->daysRemaining} day".($this->daysRemaining === 1 ? '' : 's');

        return $this->subject($subject)->view('emails.trial-ending', ['daysRemaining' => $this->daysRemaining]);
    }
}
