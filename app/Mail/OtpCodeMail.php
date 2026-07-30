<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromModuleAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OtpCodeMail extends Mailable implements ShouldQueue
{
    use Queueable, SendsFromModuleAddress, SerializesModels;

    public function __construct(public readonly string $code) {}

    public function build(): self
    {
        return $this->fromModule('no_reply')
            ->subject('Your StockBeat sign-in code')
            ->view('emails.otp-code');
    }
}
