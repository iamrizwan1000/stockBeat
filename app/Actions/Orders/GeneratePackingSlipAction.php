<?php

namespace App\Actions\Orders;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * "Share packing slip as PDF" (Plan §4.3) — generated server-side so it can
 * be shared straight from the native share sheet without the app itself
 * needing any PDF-rendering capability.
 */
class GeneratePackingSlipAction
{
    public function handle(Order $order): Response
    {
        $order->loadMissing(['items', 'connection']);

        return Pdf::loadView('orders.packing-slip', ['order' => $order])
            ->stream("packing-slip-{$order->order_number}.pdf");
    }
}
