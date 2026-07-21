<?php

use App\Models\Order;
use App\Support\Connections\Adapters\Woo\WooOrderMapper;

function sampleWooOrderPayload(array $overrides = []): array
{
    return array_merge([
        'id' => 727,
        'number' => '727',
        'status' => 'processing',
        'currency' => 'USD',
        'date_created_gmt' => '2026-07-16T10:00:00',
        'total' => '59.98',
        'discount_total' => '5.00',
        'total_tax' => '4.50',
        'billing' => [
            'first_name' => 'Jane',
            'last_name' => 'Buyer',
            'email' => 'jane@example.com',
        ],
        'shipping' => [
            'address_1' => '123 Main St',
            'address_2' => '',
            'city' => 'Sydney',
            'state' => 'NSW',
            'postcode' => '2000',
            'country' => 'AU',
        ],
        'line_items' => [
            [
                'id' => 315,
                'name' => 'Blue Widget',
                'sku' => 'WIDGET-BLUE',
                'quantity' => 2,
                'total' => '39.98',
                'image' => ['src' => 'https://example.com/widget.jpg'],
            ],
            [
                'id' => 316,
                'name' => 'Red Widget',
                'sku' => '',
                'quantity' => 1,
                'total' => '20.00',
                'image' => null,
            ],
        ],
    ], $overrides);
}

test('maps a real woo order payload accurately', function () {
    $order = app(WooOrderMapper::class)->map(sampleWooOrderPayload());

    expect($order->externalId)->toBe('727');
    expect($order->orderNumber)->toBe('#727');
    expect($order->status)->toBe(Order::STATUS_UNFULFILLED);
    expect($order->fulfillmentStatus)->toBe(Order::FULFILLMENT_UNFULFILLED);
    expect($order->paymentStatus)->toBe(Order::PAYMENT_PAID);
    expect($order->currency)->toBe('USD');
    expect($order->total)->toBe(59.98);
    expect($order->discountAmount)->toBe(5.00);
    expect($order->tax)->toBe(4.50);
    expect($order->customerName)->toBe('Jane Buyer');
    expect($order->customerEmail)->toBe('jane@example.com');
    expect($order->shippingAddress['country'])->toBe('AU');
    expect($order->isTest)->toBeFalse();
    expect($order->shipByAt)->toBeNull();

    expect($order->items)->toHaveCount(2);
    expect($order->items[0]->sku)->toBe('WIDGET-BLUE');
    expect($order->items[0]->qty)->toBe(2);
    expect($order->items[0]->price)->toBe(19.99);
    expect($order->items[0]->imageUrl)->toBe('https://example.com/widget.jpg');
    expect($order->items[1]->sku)->toBeNull();
    expect($order->items[1]->imageUrl)->toBeNull();
});

test('maps each woo status to the correct internal vocabulary', function (string $wooStatus, string $status, string $fulfillment, string $payment) {
    $order = app(WooOrderMapper::class)->map(sampleWooOrderPayload(['status' => $wooStatus]));

    expect($order->status)->toBe($status);
    expect($order->fulfillmentStatus)->toBe($fulfillment);
    expect($order->paymentStatus)->toBe($payment);
})->with([
    'pending' => ['pending', Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
    'on-hold' => ['on-hold', Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
    'processing' => ['processing', Order::STATUS_UNFULFILLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PAID],
    'completed' => ['completed', Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_PAID],
    'cancelled' => ['cancelled', Order::STATUS_CANCELLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
    'refunded' => ['refunded', Order::STATUS_REFUNDED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_REFUNDED],
    'failed' => ['failed', Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_FAILED],
    'unknown' => ['some-custom-status', Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
]);

test('discount and tax stay null (never fabricated) when Woo does not send them', function () {
    $payload = sampleWooOrderPayload();
    unset($payload['discount_total'], $payload['total_tax']);

    $order = app(WooOrderMapper::class)->map($payload);

    expect($order->discountAmount)->toBeNull();
    expect($order->tax)->toBeNull();
});
