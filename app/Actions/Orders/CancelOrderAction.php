<?php

namespace App\Actions\Orders;

use App\Models\Order;
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
        $adapter = $this->adapters->driver($order->platform);

        if (! $adapter->capabilities()->cancel) {
            throw ValidationException::withMessages([
                'order' => 'This channel doesn\'t support cancelling orders from here.',
            ]);
        }

        return $adapter->cancel($order, $reason);
    }
}
