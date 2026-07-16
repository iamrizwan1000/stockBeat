<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Support\Connections\ActionResult;
use App\Support\Connections\ChannelAdapterManager;
use App\Support\Connections\RefundData;
use Illuminate\Validation\ValidationException;

/**
 * Full or partial refund (Plan §4.3), delegated to the order's own
 * `ChannelAdapter`.
 */
class RefundOrderAction
{
    public function __construct(
        private readonly ChannelAdapterManager $adapters,
    ) {}

    public function handle(Order $order, ?float $amount, ?string $reason): ActionResult
    {
        $adapter = $this->adapters->driver($order->platform);

        if (! $adapter->capabilities()->refunds) {
            throw ValidationException::withMessages([
                'order' => 'This channel doesn\'t support refunds from here.',
            ]);
        }

        if ($amount !== null && $amount > $order->total) {
            throw ValidationException::withMessages([
                'amount' => 'The refund amount can\'t exceed the order total.',
            ]);
        }

        return $adapter->refund($order, new RefundData($amount, $reason));
    }
}
