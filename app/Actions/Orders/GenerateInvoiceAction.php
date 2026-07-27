<?php

namespace App\Actions\Orders;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * "Get an invoice PDF" (Plan §4.18) — the priced, customer-facing
 * counterpart to the packing slip (§4.3), which deliberately omits price.
 * Generated server-side so it can be shared straight from the native share
 * sheet without the app itself needing any PDF-rendering capability.
 */
class GenerateInvoiceAction
{
    public function handle(Order $order): Response
    {
        $order->loadMissing(['items', 'connection']);

        return Pdf::loadView('orders.invoice', ['order' => $order])
            ->stream("invoice-{$order->order_number}.pdf");
    }
}
