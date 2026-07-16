<?php

namespace App\Actions\Orders;

use App\Models\Order;

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

        return $order;
    }
}
