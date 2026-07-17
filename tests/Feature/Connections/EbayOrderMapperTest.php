<?php

use App\Models\Order;
use App\Support\Connections\Adapters\Ebay\EbayOrderMapper;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ebayRawOrder(array $overrides = []): array
{
    return array_merge([
        'orderId' => '11-22333-44555',
        'orderFulfillmentStatus' => 'NOT_STARTED',
        'orderPaymentStatus' => 'PAID',
        'cancelStatus' => ['cancelState' => 'NONE_REQUESTED'],
        'pricingSummary' => ['total' => ['value' => '20.00', 'currency' => 'USD']],
        'buyer' => ['username' => 'buyer123'],
        'fulfillmentStartInstructions' => [[
            'shippingStep' => [
                'shipTo' => [
                    'fullName' => 'Jane Buyer',
                    'contactAddress' => ['addressLine1' => '1 Main St', 'city' => 'Sydney', 'countryCode' => 'AU'],
                ],
            ],
        ]],
        'creationDate' => '2026-07-16T10:00:00.000Z',
        'lineItems' => [],
    ], $overrides);
}

test('a cancelled order maps to cancelled', function () {
    $mapped = app(EbayOrderMapper::class)->map(ebayRawOrder(['cancelStatus' => ['cancelState' => 'CANCELED']]));

    expect($mapped->status)->toBe(Order::STATUS_CANCELLED);
});

test('a fully refunded order maps to refunded', function () {
    $mapped = app(EbayOrderMapper::class)->map(ebayRawOrder(['orderPaymentStatus' => 'FULLY_REFUNDED']));

    expect($mapped->status)->toBe(Order::STATUS_REFUNDED);
    expect($mapped->paymentStatus)->toBe(Order::PAYMENT_REFUNDED);
});

test('a fulfilled order maps to shipped', function () {
    $mapped = app(EbayOrderMapper::class)->map(ebayRawOrder(['orderFulfillmentStatus' => 'FULFILLED']));

    expect($mapped->status)->toBe(Order::STATUS_SHIPPED);
    expect($mapped->fulfillmentStatus)->toBe(Order::FULFILLMENT_FULFILLED);
});

test('customer email is always null — ebay does not expose it', function () {
    $mapped = app(EbayOrderMapper::class)->map(ebayRawOrder());

    expect($mapped->customerEmail)->toBeNull();
    expect($mapped->customerName)->toBe('Jane Buyer');
});

test('falls back to buyer username when no shipTo full name is present', function () {
    $mapped = app(EbayOrderMapper::class)->map(ebayRawOrder(['fulfillmentStartInstructions' => []]));

    expect($mapped->customerName)->toBe('buyer123');
});

test('shipping address fields map from contactAddress', function () {
    $mapped = app(EbayOrderMapper::class)->map(ebayRawOrder());

    expect($mapped->shippingAddress['line1'])->toBe('1 Main St');
    expect($mapped->shippingAddress['city'])->toBe('Sydney');
    expect($mapped->shippingAddress['country'])->toBe('AU');
});

test('isTest is always false — ebay sandbox has no per-order test flag', function () {
    $mapped = app(EbayOrderMapper::class)->map(ebayRawOrder());

    expect($mapped->isTest)->toBeFalse();
});
