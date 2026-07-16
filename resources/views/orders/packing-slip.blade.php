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
        .totals { margin-top: 16px; text-align: right; font-size: 14px; }
    </style>
</head>
<body>
    <h1>Packing Slip</h1>
    <div class="meta">
        Order {{ $order->order_number }} &middot; {{ $order->connection->name ?? $order->platform }} &middot; {{ $order->placed_at->format('M j, Y') }}
    </div>

    <div class="columns">
        <div class="column">
            <div class="section-title">Ship to</div>
            @if ($order->customer_name)
                <div>{{ $order->customer_name }}</div>
            @endif
            @php $address = $order->shipping_address ?? []; @endphp
            @if (! empty($address['line1']))
                <div>{{ $address['line1'] }}</div>
            @endif
            @if (! empty($address['line2']))
                <div>{{ $address['line2'] }}</div>
            @endif
            <div>
                {{ collect([$address['city'] ?? null, $address['state'] ?? null, $address['postcode'] ?? null])->filter()->implode(', ') }}
            </div>
            @if (! empty($address['country']))
                <div>{{ $address['country'] }}</div>
            @endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>SKU</th>
                <th>Qty</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $item->title }}</td>
                    <td>{{ $item->sku ?? '—' }}</td>
                    <td>{{ $item->qty }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        Total: {{ $order->currency }} {{ number_format($order->total, 2) }}
    </div>
</body>
</html>
