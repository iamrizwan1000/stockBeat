<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .meta { color: #555; margin-bottom: 20px; }
        .section-title { font-size: 11px; text-transform: uppercase; color: #888; margin-bottom: 4px; }
        .columns { display: flex; justify-content: space-between; margin-bottom: 24px; }
        .column { width: 48%; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd; }
        th { background: #f5f5f5; font-size: 11px; text-transform: uppercase; color: #666; }
        td.numeric, th.numeric { text-align: right; }
        .totals { margin-top: 16px; text-align: right; }
        .totals .row { margin-bottom: 4px; }
        .totals .grand { font-size: 14px; font-weight: bold; margin-top: 8px; }
    </style>
</head>
<body>
    <h1>Invoice</h1>
    <div class="meta">
        Order {{ $order->order_number }} &middot; {{ $order->connection->name ?? $order->platform }} &middot; {{ $order->placed_at->format('M j, Y') }}
    </div>

    <div class="columns">
        <div class="column">
            <div class="section-title">Billed to</div>
            @if ($order->customer_name)
                <div>{{ $order->customer_name }}</div>
            @endif
            @if ($order->customer_email)
                <div>{{ $order->customer_email }}</div>
            @endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>SKU</th>
                <th class="numeric">Qty</th>
                <th class="numeric">Unit price</th>
                <th class="numeric">Line total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $item->title }}</td>
                    <td>{{ $item->sku ?? '—' }}</td>
                    <td class="numeric">{{ $item->qty }}</td>
                    <td class="numeric">{{ $order->currency }} {{ number_format($item->price, 2) }}</td>
                    <td class="numeric">{{ $order->currency }} {{ number_format($item->price * $item->qty, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        @php $subtotal = $order->items->sum(fn ($item) => $item->price * $item->qty); @endphp
        <div class="row">Subtotal: {{ $order->currency }} {{ number_format($subtotal, 2) }}</div>
        @if ($order->discount_amount !== null && $order->discount_amount > 0)
            <div class="row">Discount: -{{ $order->currency }} {{ number_format($order->discount_amount, 2) }}</div>
        @endif
        @if ($order->tax !== null && $order->tax > 0)
            <div class="row">Tax: {{ $order->currency }} {{ number_format($order->tax, 2) }}</div>
        @endif
        <div class="grand">Total: {{ $order->currency }} {{ number_format($order->total, 2) }}</div>
    </div>
</body>
</html>
