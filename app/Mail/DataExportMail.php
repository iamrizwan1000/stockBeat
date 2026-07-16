<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class DataExportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $json,
    ) {}

    public function build(): self
    {
        return $this->subject('Your StockBeat data export')
            ->view('emails.data-export')
            ->attach(Attachment::fromData(fn () => $this->json, 'stockbeat-data-export.json')
                ->withMime('application/json'));
    }
}
