<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderEvent;

/**
 * Stores tags locally (Plan §4.3: "stored in OrderPulse, synced to platform
 * where supported" — platform sync is the adapter's job, deferred alongside
 * fulfill/refund/cancel).
 */
class UpdateOrderTagsAction
{
    /**
     * @param  array<int, string>  $tags
     */
    public function handle(Order $order, array $tags): Order
    {
        $order->update(['tags' => array_values(array_unique($tags))]);

        OrderEvent::query()->create([
            'order_id' => $order->id,
            'type' => OrderEvent::TYPE_TAGS_UPDATED,
            'payload' => ['tags' => $order->tags],
            'occurred_at' => now(),
        ]);

        return $order;
    }
}
