<?php

namespace App\Actions\Inbox;

use App\Models\InboxThread;
use App\Models\Order;

/**
 * Plan §4.5: "Shopify/Woo via order-linked email threading." One thread
 * per order — resumed, not recreated, on repeat contact about the same order.
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
                'last_message_at' => now(),
            ],
        );
    }
}
