<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromModuleAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A staff reply landing in the seller's own email.
 *
 * Carries the plus-addressed `Reply-To` (`support+{threadId}@{inbound domain}`)
 * that `ParseInboundEmailTokenAction` routes back into this same support
 * thread — the same mechanism `InboxMessageMail` uses for customer threads.
 * Without it a seller hitting "reply" would land on the bare `support@` From
 * address, which the inbound router can't thread because it matches only on
 * the `support+{id}` local part.
 */
class SupportReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SendsFromModuleAddress, SerializesModels;

    public function __construct(
        public readonly string $body,
        public readonly ?int $threadId = null,
    ) {}

    public function build(): self
    {
        $mail = $this->fromModule('support')
            ->subject('New reply to your StockBeat support request')
            ->view('emails.support-reply');

        if ($this->threadId !== null) {
            $domain = config('services.inbound_email.domain');

            if (is_string($domain) && $domain !== '') {
                $mail = $mail->replyTo("support+{$this->threadId}@{$domain}");
            }
        }

        return $mail;
    }
}
