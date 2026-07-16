<?php

namespace App\Actions\Inbox;

use App\Actions\Notifications\SendPushNotificationAction;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Notification;
use Illuminate\Support\Str;

/**
 * A customer's email reply threading back into the unified inbox (Plan
 * §4.5/§7.7). The `from` address must match the thread's own customer
 * email — otherwise anyone who learns a `thread+{id}@` address could inject
 * a fake "customer reply" into a merchant's inbox. A mismatch is silently
 * dropped, same posture as `ReceiveInboundEmailReplyAction`'s support-side
 * equivalent.
 *
 * "Push notification on new buyer message" (Plan §4.5) is sent directly
 * here rather than routed through the rules engine's trigger system — a
 * dedicated `new_inbox_message` trigger would need its own rule type,
 * condition support, and dedup key; this is the simpler, still-real cut
 * until that's worth building.
 */
class ReceiveInboxReplyAction
{
    public function __construct(
        private readonly SendPushNotificationAction $sendPush,
    ) {}

    public function handle(InboxThread $thread, string $fromEmail, string $body): ?InboxMessage
    {
        if ($thread->customer_email === null || strcasecmp($thread->customer_email, $fromEmail) !== 0) {
            return null;
        }

        $message = InboxMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => InboxMessage::DIRECTION_IN,
            'body' => $body,
            'status' => InboxMessage::STATUS_DELIVERED,
        ]);

        $thread->update(['last_message_at' => $message->created_at]);

        $recipient = $thread->assignedTo ?? $thread->team->owner;

        $this->sendPush->handle(
            $recipient,
            'New customer message',
            Str::limit($body, 100),
            ['thread_id' => $thread->id],
            Notification::TYPE_INBOX_MESSAGE,
        );

        return $message;
    }
}
