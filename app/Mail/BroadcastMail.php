<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * A real recipient's broadcast email (Plan §8.7.5) — as opposed to
 * `BroadcastTestMail`, sent only to the requesting admin's own inbox and
 * carrying no delivery/tracking identity. Signed (not just plain) tracking
 * and unsubscribe URLs so a guessed `{delivery}` id can't be used to mark
 * another recipient's email opened or flip their preferences.
 */
class BroadcastMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly int $deliveryId,
    ) {}

    public function build(): self
    {
        return $this->subject($this->title)
            ->view('emails.broadcast')
            ->with([
                'trackingPixelUrl' => URL::signedRoute('broadcasts.track-open', ['delivery' => $this->deliveryId]),
                'unsubscribeUrl' => URL::signedRoute('broadcasts.unsubscribe', ['delivery' => $this->deliveryId]),
            ]);
    }
}
