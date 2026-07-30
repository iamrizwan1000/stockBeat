<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Support\Connections\ActionResult;
use App\Support\Connections\ChannelAdapterManager;
use Illuminate\Validation\ValidationException;

/**
 * Cancel order (Plan §4.3), delegated to the order's own `ChannelAdapter`.
 */
class CancelOrderAction
{
    public function __construct(
        private readonly ChannelAdapterManager $adapters,
    ) {}

    public function handle(Order $order, ?string $reason): ActionResult
    {
        // See FulfillOrderAction's identical note — the platform's own PUT
        // is naturally idempotent, this just avoids a wasted call and a
        // duplicate OrderEvent row on a double-tap.
        if ($order->status === Order::STATUS_CANCELLED) {
            return ActionResult::success('This order has already been cancelled.');
        }

        $adapter = $this->adapters->driver($order->platform);

        if (! $adapter->capabilities()->cancel) {
            throw ValidationException::withMessages([
                'order' => 'This channel doesn\'t support cancelling orders from here.',
            ]);
        }

        $result = $adapter->cancel($order, $reason);

        if ($result->success) {
            OrderEvent::query()->create([
                'order_id' => $order->id,
                'type' => OrderEvent::TYPE_CANCELLED,
                'payload' => ['reason' => $reason],
                'occurred_at' => now(),
            ]);
        }

        return $result;
    }
}
