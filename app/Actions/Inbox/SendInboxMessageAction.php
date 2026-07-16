<?php

namespace App\Actions\Inbox;

use App\Mail\InboxMessageMail;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Outbound customer reply (Plan §4.5). Sent from our own domain with a
 * plus-addressed Reply-To — not "the merchant's connected email," since no
 * mailbox-connection flow exists (the spec offers that as an alternative;
 * this implements the other half, "our sending domain with reply-to").
 */
class SendInboxMessageAction
{
    public function handle(User $sender, InboxThread $thread, string $body): InboxMessage
    {
        $message = InboxMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => InboxMessage::DIRECTION_OUT,
            'body' => $body,
            'sent_by' => $sender->id,
            'status' => InboxMessage::STATUS_QUEUED,
        ]);

        $thread->update(['last_message_at' => $message->created_at]);

        if ($thread->customer_email !== null) {
            $domain = config('services.inbound_email.domain');
            $replyTo = "thread+{$thread->id}@{$domain}";

            Mail::to($thread->customer_email)->queue(new InboxMessageMail($body, $replyTo));
            $message->update(['status' => InboxMessage::STATUS_SENT]);
        } else {
            $message->update(['status' => InboxMessage::STATUS_FAILED]);
        }

        return $message;
    }
}
