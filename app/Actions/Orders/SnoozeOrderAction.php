<?php

namespace App\Actions\Orders;

use App\Models\Order;
use Carbon\CarbonInterface;

/**
 * Snooze / remind-me-later (Plan §4.2). Passing `null` clears the snooze —
 * the feed's default filtering (see `ListOrdersAction`) hides an order
 * while `snoozed_until` is in the future.
 */
class SnoozeOrderAction
{
    public function handle(Order $order, ?CarbonInterface $until): Order
    {
        $order->update(['snoozed_until' => $until]);

        return $order;
    }
}
