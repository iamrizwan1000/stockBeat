<?php

namespace App\Actions\Inbox;

use App\Models\InboxThread;
use App\Models\Order;

/**
 * Plan §4.5: one thread per order — resumed, not recreated, on repeat
 * contact about the same order. `channel` carries the order's own platform
 * (shopify/woo/ebay/etsy/amazon) so `SendInboxMessageAction` can route the
 * reply correctly — email for Shopify/Woo, the real adapter's
 * `sendMessage()` otherwise. For eBay, also seeds the buyer username/legacy
 * ItemID the Trading API needs to address a reply (captured at order-ingest
 * time by `EbayOrderMapper`/`IngestOrderAction`) — a pre-sale eBay message
 * with no order yet is instead seeded directly by
 * `IngestEbayMemberMessageAction`.
 */
class GetOrCreateInboxThreadAction
{
    public function handle(Order $order): InboxThread
    {
        return InboxThread::query()->firstOrCreate(
            ['order_id' => $order->id],
            [
                'team_id' => $order->team_id,
                'connection_id' => $order->connection_id,
                'channel' => $order->platform,
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'external_buyer_username' => $order->buyer_username,
                'external_item_id' => $order->items()->whereNotNull('legacy_item_id')->value('legacy_item_id'),
                'last_message_at' => now(),
            ],
        );
    }
}
