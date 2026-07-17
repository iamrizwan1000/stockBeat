<?php

use App\Models\Order;
use App\Support\Connections\Adapters\Shopify\ShopifyOrderMapper;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shopifyRawOrder(array $overrides = []): array
{
    return array_merge([
        'id' => 1,
        'name' => '#1',
        'financial_status' => 'pending',
        'fulfillment_status' => null,
        'cancelled_at' => null,
        'test' => false,
        'currency' => 'USD',
        'total_price' => '10.00',
        'customer' => ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.com'],
        'shipping_address' => [],
        'created_at' => '2026-07-16T10:00:00-00:00',
        'tags' => '',
        'line_items' => [],
    ], $overrides);
}

test('a cancelled order maps to cancelled regardless of financial status', function () {
    $mapped = app(ShopifyOrderMapper::class)->map(shopifyRawOrder(['cancelled_at' => '2026-07-16T12:00:00-00:00', 'financial_status' => 'paid']));

    expect($mapped->status)->toBe(Order::STATUS_CANCELLED);
});

test('a fully refunded order maps to refunded', function () {
    $mapped = app(ShopifyOrderMapper::class)->map(shopifyRawOrder(['financial_status' => 'refunded']));

    expect($mapped->status)->toBe(Order::STATUS_REFUNDED);
    expect($mapped->paymentStatus)->toBe(Order::PAYMENT_REFUNDED);
});

test('a partially refunded order keeps refunded status with partially_refunded payment', function () {
    $mapped = app(ShopifyOrderMapper::class)->map(shopifyRawOrder(['financial_status' => 'partially_refunded', 'fulfillment_status' => 'fulfilled']));

    expect($mapped->status)->toBe(Order::STATUS_REFUNDED);
    expect($mapped->paymentStatus)->toBe(Order::PAYMENT_PARTIALLY_REFUNDED);
    expect($mapped->fulfillmentStatus)->toBe(Order::FULFILLMENT_FULFILLED);
});

test('a fulfilled order maps to shipped', function () {
    $mapped = app(ShopifyOrderMapper::class)->map(shopifyRawOrder(['fulfillment_status' => 'fulfilled', 'financial_status' => 'paid']));

    expect($mapped->status)->toBe(Order::STATUS_SHIPPED);
    expect($mapped->fulfillmentStatus)->toBe(Order::FULFILLMENT_FULFILLED);
});

test('a partially fulfilled, paid order maps to unfulfilled status with partial fulfillment', function () {
    $mapped = app(ShopifyOrderMapper::class)->map(shopifyRawOrder(['fulfillment_status' => 'partial', 'financial_status' => 'paid']));

    expect($mapped->status)->toBe(Order::STATUS_UNFULFILLED);
    expect($mapped->fulfillmentStatus)->toBe(Order::FULFILLMENT_PARTIAL);
    expect($mapped->paymentStatus)->toBe(Order::PAYMENT_PAID);
});

test('a pending order with no fulfillment maps to unfulfilled/pending', function () {
    $mapped = app(ShopifyOrderMapper::class)->map(shopifyRawOrder());

    expect($mapped->status)->toBe(Order::STATUS_UNFULFILLED);
    expect($mapped->fulfillmentStatus)->toBe(Order::FULFILLMENT_UNFULFILLED);
    expect($mapped->paymentStatus)->toBe(Order::PAYMENT_PENDING);
});

test('the test flag is preserved for the analytics/digest exclusion', function () {
    $mapped = app(ShopifyOrderMapper::class)->map(shopifyRawOrder(['test' => true]));

    expect($mapped->isTest)->toBeTrue();
});

test('line item price is used as-is (already per-unit, unlike Woo line totals)', function () {
    $mapped = app(ShopifyOrderMapper::class)->map(shopifyRawOrder([
        'line_items' => [['id' => 1, 'title' => 'Widget', 'sku' => 'W1', 'quantity' => 3, 'price' => '9.99']],
    ]));

    expect($mapped->items[0]->price)->toBe(9.99);
    expect($mapped->items[0]->qty)->toBe(3);
});

test('comma-separated tags are split into an array', function () {
    $mapped = app(ShopifyOrderMapper::class)->map(shopifyRawOrder(['tags' => 'vip, rush order']));

    expect($mapped->tags)->toBe(['vip', 'rush order']);
});
