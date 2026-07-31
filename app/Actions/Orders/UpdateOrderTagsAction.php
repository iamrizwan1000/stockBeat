<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Support\Connections\Adapters\ShopifyAdapter;
use App\Support\Connections\ChannelAdapterManager;

/**
 * Stores tags locally, always (Plan §4.3/§4.17 — the local write never
 * fails once ownership is confirmed, `BulkTagOrdersAction`'s own docblock
 * relies on that). Also pushes the change to the platform's own order when
 * the connection supports it (`capabilities()->tagSync` — Shopify only for
 * now) — best-effort, see `ShopifyAdapter::updateOrderTags()`'s own
 * docblock for why a sync failure never blocks or reverts the local write.
 */
class UpdateOrderTagsAction
{
    public function __construct(
        private readonly ChannelAdapterManager $adapters,
    ) {}

    /**
     * @param  array<int, string>  $tags
     */
    public function handle(Order $order, array $tags): Order
    {
        $newTags = array_values(array_unique($tags));
        $previousTags = array_values($order->tags ?? []);

        if ($newTags === $previousTags) {
            // A repeat submission (e.g. a bulk-tag double-tap) resulting in
            // the identical tag list is a no-op — skip the redundant write
            // and the duplicate timeline entry, same treatment
            // fulfill/cancel already got for their own repeat case.
            return $order;
        }

        $order->update(['tags' => $newTags]);

        OrderEvent::query()->create([
            'order_id' => $order->id,
            'type' => OrderEvent::TYPE_TAGS_UPDATED,
            'payload' => ['tags' => $order->tags],
            'occurred_at' => now(),
        ]);

        $adapter = $this->adapters->driver($order->platform);

        if ($adapter->capabilities()->tagSync && $adapter instanceof ShopifyAdapter) {
            $adapter->updateOrderTags($order, $previousTags, $newTags);
        }

        return $order;
    }
}
