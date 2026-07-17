<?php

use App\Models\Order;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\EbayAdapter;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ebay.env' => 'sandbox']);
});

function ebayOrderForActions(array $overrides = []): Order
{
    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'credentials' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expires_at' => now()->addHour()->toIso8601String()],
    ]);

    return Order::factory()->create(array_merge([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_EBAY,
        'external_id' => '11-22333-44555',
        'currency' => 'USD',
        'total' => 100.00,
    ], $overrides));
}

test('fulfill looks up line items and submits a shipping fulfillment', function () {
    Http::fake([
        'api.sandbox.ebay.com/sell/fulfillment/v1/order/11-22333-44555' => Http::response([
            'lineItems' => [['lineItemId' => 'li-1', 'quantity' => 2]],
        ], 200),
        'api.sandbox.ebay.com/sell/fulfillment/v1/order/11-22333-44555/shipping_fulfillment' => Http::response(['fulfillmentId' => '1'], 201),
    ]);

    $order = ebayOrderForActions();

    $result = app(EbayAdapter::class)->fulfill($order, new FulfillmentData('1Z999', 'UPS'));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'shipping_fulfillment')
        && ($request['lineItems'][0]['lineItemId'] ?? null) === 'li-1'
        && ($request['trackingNumber'] ?? null) === '1Z999');
});

test('fulfill fails cleanly when the order has no line items', function () {
    Http::fake([
        'api.sandbox.ebay.com/sell/fulfillment/v1/order/11-22333-44555' => Http::response(['lineItems' => []], 200),
    ]);

    $order = ebayOrderForActions();

    $result = app(EbayAdapter::class)->fulfill($order, new FulfillmentData('1Z999'));

    expect($result->success)->toBeFalse();
});

test('refund issues an order-level refund', function () {
    Http::fake([
        'api.sandbox.ebay.com/sell/fulfillment/v1/order/11-22333-44555/issue_refund' => Http::response(['refundId' => '1'], 200),
    ]);

    $order = ebayOrderForActions(['total' => 100.00, 'currency' => 'USD']);

    $result = app(EbayAdapter::class)->refund($order, new RefundData(amount: 100.00, reason: 'not as described'));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_REFUNDED);
    expect($order->fresh()->payment_status)->toBe(Order::PAYMENT_REFUNDED);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'issue_refund')
        && ($request['orderLevelRefundAmount']['value'] ?? null) === '100'
        && ($request['orderLevelRefundAmount']['currency'] ?? null) === 'USD');
});

test('a partial refund marks the order partially refunded', function () {
    Http::fake(['api.sandbox.ebay.com/sell/fulfillment/v1/order/11-22333-44555/issue_refund' => Http::response([], 200)]);

    $order = ebayOrderForActions(['total' => 100.00]);

    $result = app(EbayAdapter::class)->refund($order, new RefundData(amount: 20.00));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->payment_status)->toBe(Order::PAYMENT_PARTIALLY_REFUNDED);
});

test('cancel calls the post-order cancellation endpoint', function () {
    Http::fake(['api.sandbox.ebay.com/post-order/v2/cancellation' => Http::response(['cancelId' => '1'], 200)]);

    $order = ebayOrderForActions();

    $result = app(EbayAdapter::class)->cancel($order, 'Out of stock');

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/post-order/v2/cancellation')
        && ($request['legacyOrderId'] ?? null) === '11-22333-44555');
});

test('refreshAuth updates the access token and expiry on success', function () {
    Http::fake(['api.sandbox.ebay.com/identity/v1/oauth2/token' => Http::response([
        'access_token' => 'new-token',
        'expires_in' => 7200,
    ], 200)]);

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'credentials' => ['access_token' => 'old-token', 'refresh_token' => 'refresh-abc', 'expires_at' => now()->subMinute()->toIso8601String()],
    ]);

    app(EbayAdapter::class)->refreshAuth($connection);

    expect($connection->fresh()->credentials['access_token'])->toBe('new-token');
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('refreshAuth marks needs_reauth when the refresh call fails', function () {
    Http::fake(['api.sandbox.ebay.com/identity/v1/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'credentials' => ['access_token' => 'old-token', 'refresh_token' => 'refresh-abc', 'expires_at' => now()->subMinute()->toIso8601String()],
    ]);

    app(EbayAdapter::class)->refreshAuth($connection);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
});
