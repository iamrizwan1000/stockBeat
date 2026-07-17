<?php

use App\Models\Order;
use App\Support\Connections\Adapters\Etsy\EtsyOrderMapper;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function etsyRawReceipt(array $overrides = []): array
{
    return array_merge([
        'receipt_id' => 1,
        'status' => 'open',
        'was_shipped' => false,
        'was_paid' => true,
        'name' => 'Jane Buyer',
        'first_line' => '1 Main St',
        'city' => 'Sydney',
        'country_iso' => 'AU',
        'buyer_email' => 'jane@example.com',
        'grandtotal' => ['amount' => 2000, 'divisor' => 100, 'currency_code' => 'USD'],
        'created_timestamp' => 1752654000,
        'transactions' => [],
    ], $overrides);
}

test('a canceled receipt maps to cancelled', function () {
    $mapped = app(EtsyOrderMapper::class)->map(etsyRawReceipt(['status' => 'canceled']));

    expect($mapped->status)->toBe(Order::STATUS_CANCELLED);
});

test('a shipped receipt maps to shipped/fulfilled', function () {
    $mapped = app(EtsyOrderMapper::class)->map(etsyRawReceipt(['was_shipped' => true, 'was_paid' => true]));

    expect($mapped->status)->toBe(Order::STATUS_SHIPPED);
    expect($mapped->fulfillmentStatus)->toBe(Order::FULFILLMENT_FULFILLED);
    expect($mapped->paymentStatus)->toBe(Order::PAYMENT_PAID);
});

test('a paid unshipped receipt maps to unfulfilled/paid', function () {
    $mapped = app(EtsyOrderMapper::class)->map(etsyRawReceipt());

    expect($mapped->status)->toBe(Order::STATUS_UNFULFILLED);
    expect($mapped->fulfillmentStatus)->toBe(Order::FULFILLMENT_UNFULFILLED);
    expect($mapped->paymentStatus)->toBe(Order::PAYMENT_PAID);
});

test('an unpaid receipt maps to new/pending', function () {
    $mapped = app(EtsyOrderMapper::class)->map(etsyRawReceipt(['was_paid' => false]));

    expect($mapped->status)->toBe(Order::STATUS_NEW);
    expect($mapped->paymentStatus)->toBe(Order::PAYMENT_PENDING);
});

test('money fields are divided by the divisor', function () {
    $mapped = app(EtsyOrderMapper::class)->map(etsyRawReceipt(['grandtotal' => ['amount' => 4999, 'divisor' => 100, 'currency_code' => 'USD']]));

    expect($mapped->total)->toBe(49.99);
});

test('customer email comes from buyer_email, unlike ebay', function () {
    $mapped = app(EtsyOrderMapper::class)->map(etsyRawReceipt());

    expect($mapped->customerEmail)->toBe('jane@example.com');
});

test('transaction line items have per-unit money values divided by the divisor', function () {
    $mapped = app(EtsyOrderMapper::class)->map(etsyRawReceipt([
        'transactions' => [['transaction_id' => 1, 'sku' => 'SKU1', 'title' => 'Widget', 'quantity' => 2, 'price' => ['amount' => 999, 'divisor' => 100, 'currency_code' => 'USD']]],
    ]));

    expect($mapped->items[0]->price)->toBe(9.99);
    expect($mapped->items[0]->qty)->toBe(2);
});

test('isTest is always false', function () {
    $mapped = app(EtsyOrderMapper::class)->map(etsyRawReceipt());

    expect($mapped->isTest)->toBeFalse();
});
