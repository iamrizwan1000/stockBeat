<?php

use App\Models\Order;
use App\Support\Connections\Adapters\Amazon\AmazonOrderMapper;

function amazonRawOrder(array $overrides = []): array
{
    return array_merge([
        'AmazonOrderId' => '111-2223334-5556667',
        'PurchaseDate' => '2026-07-15T10:00:00Z',
        'OrderStatus' => 'Unshipped',
        'OrderTotal' => ['CurrencyCode' => 'USD', 'Amount' => '49.98'],
    ], $overrides);
}

test('maps an Unshipped order into new/paid/unfulfilled', function () {
    $order = (new AmazonOrderMapper)->map(amazonRawOrder());

    expect($order->externalId)->toBe('111-2223334-5556667');
    expect($order->orderNumber)->toBe('#111-2223334-5556667');
    expect($order->status)->toBe(Order::STATUS_UNFULFILLED);
    expect($order->fulfillmentStatus)->toBe(Order::FULFILLMENT_UNFULFILLED);
    expect($order->paymentStatus)->toBe(Order::PAYMENT_PAID);
    expect($order->currency)->toBe('USD');
    expect($order->total)->toBe(49.98);
    expect($order->isTest)->toBeFalse();
});

test('maps every documented Amazon order status (Plan §7.5)', function (string $amazonStatus, string $status, string $fulfillment, string $payment) {
    $order = (new AmazonOrderMapper)->map(amazonRawOrder(['OrderStatus' => $amazonStatus]));

    expect($order->status)->toBe($status);
    expect($order->fulfillmentStatus)->toBe($fulfillment);
    expect($order->paymentStatus)->toBe($payment);
})->with([
    ['Pending', Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
    ['Unshipped', Order::STATUS_UNFULFILLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PAID],
    ['PartiallyShipped', Order::STATUS_UNFULFILLED, Order::FULFILLMENT_PARTIAL, Order::PAYMENT_PAID],
    ['Shipped', Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_PAID],
    ['Canceled', Order::STATUS_CANCELLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
    ['Unfulfillable', Order::STATUS_CANCELLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
    ['InvoiceUnconfirmed', Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
]);

test('buyer PII is only mapped when the RDT-enriched fields are present', function () {
    $withoutRdt = (new AmazonOrderMapper)->map(amazonRawOrder());
    expect($withoutRdt->customerName)->toBeNull();
    expect($withoutRdt->customerEmail)->toBeNull();
    expect($withoutRdt->shippingAddress['line1'])->toBeNull();

    $withRdt = (new AmazonOrderMapper)->map(amazonRawOrder([
        'BuyerInfo' => ['BuyerEmail' => 'buyer@marketplace.amazon.com', 'BuyerName' => 'Sam Buyer'],
        'ShippingAddress' => [
            'Name' => 'Sam Buyer',
            'AddressLine1' => '123 Main St',
            'City' => 'Seattle',
            'StateOrRegion' => 'WA',
            'PostalCode' => '98101',
            'CountryCode' => 'US',
        ],
    ]));

    expect($withRdt->customerName)->toBe('Sam Buyer');
    expect($withRdt->customerEmail)->toBe('buyer@marketplace.amazon.com');
    expect($withRdt->shippingAddress['line1'])->toBe('123 Main St');
    expect($withRdt->shippingAddress['city'])->toBe('Seattle');
});

test('shipByAt maps from LatestShipDate when present', function () {
    $order = (new AmazonOrderMapper)->map(amazonRawOrder(['LatestShipDate' => '2026-07-20T23:59:59Z']));

    expect($order->shipByAt)->not->toBeNull();
    expect($order->shipByAt->toIso8601String())->toContain('2026-07-20');
});

test('order items divide the ItemPrice line total by quantity', function () {
    $order = (new AmazonOrderMapper)->map(amazonRawOrder(), [
        [
            'OrderItemId' => 'item-1',
            'SellerSKU' => 'SKU-1',
            'Title' => 'Widget',
            'QuantityOrdered' => 2,
            'ItemPrice' => ['CurrencyCode' => 'USD', 'Amount' => '39.98'],
        ],
    ]);

    expect($order->items)->toHaveCount(1);
    expect($order->items[0]->sku)->toBe('SKU-1');
    expect($order->items[0]->qty)->toBe(2);
    expect($order->items[0]->price)->toBe(19.99);
});
