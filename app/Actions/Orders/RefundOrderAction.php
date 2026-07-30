<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Support\Concurrency\IdempotencyGuard;
use App\Support\Connections\ActionResult;
use App\Support\Connections\ChannelAdapterManager;
use App\Support\Connections\RefundData;
use Illuminate\Validation\ValidationException;

/**
 * Full or partial refund (Plan §4.3), delegated to the order's own
 * `ChannelAdapter`.
 *
 * Guarded against a double-tap on a slow connection two ways: a status
 * check short-circuits an already-refunded order without calling the
 * platform again (handles the sequential retry — first call finished,
 * second call sees the updated status), and an `IdempotencyGuard` lock
 * closes the true-concurrent window the status check alone can't catch
 * (two requests both reading "not yet refunded" before either writes) —
 * without this, WooCommerce's refund endpoint creates a brand-new refund
 * transaction on every call, so a race here means real double-refunding
 * the customer, not just a wasted API call.
 */
class RefundOrderAction
{
    public function __construct(
        private readonly ChannelAdapterManager $adapters,
    ) {}

    public function handle(Order $order, ?float $amount, ?string $reason): ActionResult
    {
        if ($order->status === Order::STATUS_REFUNDED) {
            return ActionResult::success('This order has already been refunded.');
        }

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

        $result = IdempotencyGuard::once("order-action:{$order->id}:refund", 10, function () use ($order, $amount, $reason, $adapter) {
            $result = $adapter->refund($order, new RefundData($amount, $reason));

            if ($result->success) {
                OrderEvent::query()->create([
                    'order_id' => $order->id,
                    'type' => OrderEvent::TYPE_REFUNDED,
                    'payload' => ['amount' => $amount, 'reason' => $reason],
                    'occurred_at' => now(),
                ]);
            }

            return $result;
        });

        return $result ?? ActionResult::success('A refund for this order was just requested — please wait a moment before trying again.');
    }
}
