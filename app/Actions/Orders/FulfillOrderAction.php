<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Support\Connections\ActionResult;
use App\Support\Connections\ChannelAdapterManager;
use App\Support\Connections\FulfillmentData;
use Illuminate\Validation\ValidationException;

/**
 * Fulfill + tracking (Plan §4.3), delegated to the order's own
 * `ChannelAdapter` — server-enforced against `capabilities()` rather than
 * trusting that the mobile app only shows the button when supported.
 */
class FulfillOrderAction
{
    public function __construct(
        private readonly ChannelAdapterManager $adapters,
    ) {}

    public function handle(Order $order, string $trackingNumber, ?string $carrier): ActionResult
    {
        $adapter = $this->adapters->driver($order->platform);

        if (! $adapter->capabilities()->fulfillTracking) {
            throw ValidationException::withMessages([
                'order' => 'This channel doesn\'t support marking orders fulfilled from here.',
            ]);
        }

        return $adapter->fulfill($order, new FulfillmentData($trackingNumber, $carrier));
    }
}
