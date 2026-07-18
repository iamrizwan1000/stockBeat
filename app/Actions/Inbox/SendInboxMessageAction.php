<?php

namespace App\Actions\Inbox;

use App\Exceptions\Connections\AdapterNotReadyException;
use App\Mail\InboxMessageMail;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\User;
use App\Support\Connections\ChannelAdapterManager;
use Illuminate\Support\Facades\Mail;

/**
 * Outbound customer reply (Plan §4.5), routed per the thread's channel
 * capability (`capabilities()->messagingMode`, same server-enforced pattern
 * as `FulfillOrderAction`/`RefundOrderAction`):
 *  - `'email'` (Shopify/Woo, Plan §7.7): sent from our own domain with a
 *    plus-addressed Reply-To — not "the merchant's connected email," since
 *    no mailbox-connection flow exists (the spec offers that as an
 *    alternative; this implements the other half, "our sending domain with
 *    reply-to").
 *  - anything else (eBay `'full'`, Etsy `'approval_gated'`, Amazon
 *    `'template'` once that adapter exists): delegated to the channel's own
 *    `ChannelAdapter::sendMessage()`. `AdapterNotReadyException` (Etsy not
 *    yet approved, Amazon not yet built) is caught here rather than left to
 *    bubble into a 500 — the message is recorded as failed with a clear
 *    reason instead.
 */
class SendInboxMessageAction
{
    public function __construct(
        private readonly ChannelAdapterManager $adapters,
    ) {}

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

        $adapter = $this->adapters->driver($thread->channel);

        if ($adapter->capabilities()->messagingMode === 'email') {
            $this->sendViaEmail($thread, $body, $message);

            return $message;
        }

        try {
            $result = $adapter->sendMessage($thread, $body);
        } catch (AdapterNotReadyException $e) {
            $message->update(['status' => InboxMessage::STATUS_FAILED, 'failure_reason' => $e->getMessage()]);

            return $message;
        }

        $message->update([
            'status' => $result->success ? InboxMessage::STATUS_SENT : InboxMessage::STATUS_FAILED,
            'failure_reason' => $result->success ? null : $result->message,
        ]);

        return $message;
    }

    private function sendViaEmail(InboxThread $thread, string $body, InboxMessage $message): void
    {
        if ($thread->customer_email !== null) {
            $domain = config('services.inbound_email.domain');
            $replyTo = "thread+{$thread->id}@{$domain}";

            Mail::to($thread->customer_email)->queue(new InboxMessageMail($body, $replyTo));
            $message->update(['status' => InboxMessage::STATUS_SENT]);
        } else {
            $message->update([
                'status' => InboxMessage::STATUS_FAILED,
                'failure_reason' => 'This thread has no customer email on file.',
            ]);
        }
    }
}
