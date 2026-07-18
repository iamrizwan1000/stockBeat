<?php

namespace App\Actions\Inbox;

use App\Actions\Notifications\SendPushNotificationAction;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Notification;
use App\Models\Order;
use App\Models\StoreConnection;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Lands one inbound eBay Trading API member message into the unified inbox
 * (Plan §4.5/§7.3) — the inbound half of the channel `EbayAdapter::
 * sendMessage()` covers outbound. Matched to an existing order thread by
 * (buyer username, legacy ItemID) when one exists (captured at ingest by
 * `EbayOrderMapper`/`IngestOrderAction`); otherwise a standalone thread not
 * linked to any order, since eBay pre-sale questions can arrive before an
 * order ever exists. Idempotent on `inbox_messages.external_id` — a re-poll
 * of the same message (`PollEbayMessagesJob` re-covering an overlap window)
 * is a no-op.
 *
 * Known v1 gap: if a pre-sale thread like this is later followed by a real
 * order for the same (buyer, item), `GetOrCreateInboxThreadAction` matches
 * threads by `order_id` and won't find this order-less one — a second
 * thread gets created rather than the conversation continuing in one place.
 * Documented rather than silently accepted as correct; not worth the extra
 * matching complexity for v1.
 */
class IngestEbayMemberMessageAction
{
    public function __construct(
        private readonly SendPushNotificationAction $sendPush,
    ) {}

    /**
     * @param  array{external_id: string, item_id: string, buyer_username: string, body: string, created_at: CarbonInterface}  $raw
     */
    public function handle(StoreConnection $connection, array $raw): ?InboxMessage
    {
        if ($raw['external_id'] === '' || InboxMessage::query()->where('external_id', $raw['external_id'])->exists()) {
            return null;
        }

        $thread = $this->resolveThread($connection, $raw);

        $message = InboxMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => InboxMessage::DIRECTION_IN,
            'body' => $raw['body'],
            'external_id' => $raw['external_id'],
            'status' => InboxMessage::STATUS_DELIVERED,
        ]);

        $thread->update(['last_message_at' => $raw['created_at']]);

        $recipient = $thread->assignedTo ?? $thread->team->owner;

        $this->sendPush->handle(
            $recipient,
            'New customer message',
            Str::limit($raw['body'], 100),
            ['thread_id' => $thread->id],
            Notification::TYPE_INBOX_MESSAGE,
        );

        return $message;
    }

    /**
     * @param  array{item_id: string, buyer_username: string, created_at: CarbonInterface}  $raw
     */
    private function resolveThread(StoreConnection $connection, array $raw): InboxThread
    {
        $order = Order::query()
            ->where('connection_id', $connection->id)
            ->where('buyer_username', $raw['buyer_username'])
            ->whereHas('items', fn ($query) => $query->where('legacy_item_id', $raw['item_id']))
            ->latest('placed_at')
            ->first();

        return InboxThread::query()->firstOrCreate(
            [
                'connection_id' => $connection->id,
                'external_buyer_username' => $raw['buyer_username'],
                'external_item_id' => $raw['item_id'],
            ],
            [
                'team_id' => $connection->team_id,
                'order_id' => $order?->id,
                'channel' => StoreConnection::PLATFORM_EBAY,
                'customer_name' => $raw['buyer_username'],
                'customer_email' => null,
                'last_message_at' => $raw['created_at'],
            ],
        );
    }
}
