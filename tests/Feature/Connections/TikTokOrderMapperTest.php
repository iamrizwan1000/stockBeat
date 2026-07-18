<?php

use App\Models\Order;
use App\Support\Connections\Adapters\TikTok\TikTokOrderMapper;

function tiktokRawOrder(array $overrides = []): array
{
    return array_merge([
        'id' => '1729000000000000000',
        'create_time' => 1752570000, // 2025-07-15T09:00:00Z
        'order_status' => 'AWAITING_SHIPMENT',
        'payment' => ['currency' => 'USD', 'total_amount' => '49.98'],
    ], $overrides);
}

test('maps an AWAITING_SHIPMENT order into unfulfilled/paid/unfulfilled', function () {
    $order = (new TikTokOrderMapper)->map(tiktokRawOrder());

    expect($order->externalId)->toBe('1729000000000000000');
    expect($order->orderNumber)->toBe('#1729000000000000000');
    expect($order->status)->toBe(Order::STATUS_UNFULFILLED);
    expect($order->fulfillmentStatus)->toBe(Order::FULFILLMENT_UNFULFILLED);
    expect($order->paymentStatus)->toBe(Order::PAYMENT_PAID);
    expect($order->currency)->toBe('USD');
    expect($order->total)->toBe(49.98);
    expect($order->isTest)->toBeFalse();
});

test('maps every documented TikTok Shop order status (Plan §7.6)', function (string $tiktokStatus, string $status, string $fulfillment, string $payment) {
    $order = (new TikTokOrderMapper)->map(tiktokRawOrder(['order_status' => $tiktokStatus]));

    expect($order->status)->toBe($status);
    expect($order->fulfillmentStatus)->toBe($fulfillment);
    expect($order->paymentStatus)->toBe($payment);
})->with([
    ['UNPAID', Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
    ['ON_HOLD', Order::STATUS_UNFULFILLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PAID],
    ['AWAITING_SHIPMENT', Order::STATUS_UNFULFILLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PAID],
    ['PARTIALLY_SHIPPING', Order::STATUS_UNFULFILLED, Order::FULFILLMENT_PARTIAL, Order::PAYMENT_PAID],
    ['AWAITING_COLLECTION', Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_PAID],
    ['IN_TRANSIT', Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_PAID],
    ['DELIVERED', Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_PAID],
    ['COMPLETED', Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_PAID],
    ['CANCELLED', Order::STATUS_CANCELLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
]);

test('an unrecognized status falls back to new/pending', function () {
    $order = (new TikTokOrderMapper)->map(tiktokRawOrder(['order_status' => 'SOME_FUTURE_STATUS']));

    expect($order->status)->toBe(Order::STATUS_NEW);
    expect($order->paymentStatus)->toBe(Order::PAYMENT_PENDING);
});

test('customer email is always null — TikTok Shop does not expose one', function () {
    $order = (new TikTokOrderMapper)->map(tiktokRawOrder([
        'recipient_address' => ['name' => 'Sam Buyer', 'address_detail' => '123 Main St', 'city' => 'Austin', 'region_code' => 'US'],
    ]));

    expect($order->customerEmail)->toBeNull();
    expect($order->customerName)->toBe('Sam Buyer');
    expect($order->shippingAddress['line1'])->toBe('123 Main St');
    expect($order->shippingAddress['city'])->toBe('Austin');
});

test('shipByAt maps from ttl_sla_time when present', function () {
    $order = (new TikTokOrderMapper)->map(tiktokRawOrder(['ttl_sla_time' => 1753000000]));

    expect($order->shipByAt)->not->toBeNull();
});

test('order items map seller_sku, sale_price, and quantity directly (no line-total division)', function () {
    $order = (new TikTokOrderMapper)->map(tiktokRawOrder([
        'line_items' => [
            ['id' => 'item-1', 'seller_sku' => 'SKU-1', 'product_name' => 'Widget', 'quantity' => 2, 'sale_price' => '19.99', 'sku_image' => 'https://example.com/img.jpg'],
        ],
    ]));

    expect($order->items)->toHaveCount(1);
    expect($order->items[0]->sku)->toBe('SKU-1');
    expect($order->items[0]->qty)->toBe(2);
    expect($order->items[0]->price)->toBe(19.99);
    expect($order->items[0]->imageUrl)->toBe('https://example.com/img.jpg');
});
