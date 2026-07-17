<?php

use App\Models\Order;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\ShopifyAdapter;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function shopifyOrderForActions(array $overrides = []): Order
{
    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_SHOPIFY,
        'credentials' => ['shop_domain' => 'my-test-shop.myshopify.com', 'access_token' => 'shpat_faketoken'],
    ]);

    return Order::factory()->create(array_merge([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_SHOPIFY,
        'external_id' => '5551234',
        'total' => 100.00,
    ], $overrides));
}

test('fulfill looks up the open fulfillment order and creates a fulfillment with tracking', function () {
    Http::fake([
        '*/orders/5551234/fulfillment_orders.json' => Http::response(['fulfillment_orders' => [['id' => 777, 'status' => 'open']]], 200),
        '*/fulfillments.json' => Http::response(['fulfillment' => ['id' => 1]], 201),
    ]);

    $order = shopifyOrderForActions();

    $result = app(ShopifyAdapter::class)->fulfill($order, new FulfillmentData('1Z999', 'UPS'));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
    expect($order->fresh()->fulfillment_status)->toBe(Order::FULFILLMENT_FULFILLED);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/fulfillments.json')
        && ($request['fulfillment']['line_items_by_fulfillment_order'][0]['fulfillment_order_id'] ?? null) === 777
        && ($request['fulfillment']['tracking_info']['number'] ?? null) === '1Z999');
});

test('fulfill fails cleanly when there is no open fulfillment order', function () {
    Http::fake([
        '*/orders/5551234/fulfillment_orders.json' => Http::response(['fulfillment_orders' => []], 200),
    ]);

    $order = shopifyOrderForActions();

    $result = app(ShopifyAdapter::class)->fulfill($order, new FulfillmentData('1Z999'));

    expect($result->success)->toBeFalse();
    expect($order->fresh()->status)->toBe(Order::STATUS_NEW);
});

test('refund looks up the original transaction and issues a refund against it', function () {
    Http::fake([
        '*/orders/5551234/transactions.json' => Http::response(['transactions' => [
            ['id' => 111, 'kind' => 'sale', 'status' => 'success', 'gateway' => 'shopify_payments'],
        ]], 200),
        '*/orders/5551234/refunds.json' => Http::response(['refund' => ['id' => 1]], 201),
    ]);

    $order = shopifyOrderForActions(['total' => 100.00]);

    $result = app(ShopifyAdapter::class)->refund($order, new RefundData(amount: 100.00, reason: 'Customer request'));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_REFUNDED);
    expect($order->fresh()->payment_status)->toBe(Order::PAYMENT_REFUNDED);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/orders/5551234/refunds.json')
        && ($request['refund']['transactions'][0]['parent_id'] ?? null) === 111
        && ($request['refund']['transactions'][0]['gateway'] ?? null) === 'shopify_payments');
});

test('a partial refund marks the order partially refunded', function () {
    Http::fake([
        '*/orders/5551234/transactions.json' => Http::response(['transactions' => [
            ['id' => 111, 'kind' => 'capture', 'status' => 'success', 'gateway' => 'shopify_payments'],
        ]], 200),
        '*/orders/5551234/refunds.json' => Http::response(['refund' => ['id' => 1]], 201),
    ]);

    $order = shopifyOrderForActions(['total' => 100.00]);

    $result = app(ShopifyAdapter::class)->refund($order, new RefundData(amount: 25.00));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->payment_status)->toBe(Order::PAYMENT_PARTIALLY_REFUNDED);
});

test('refund fails cleanly when no successful payment transaction exists', function () {
    Http::fake([
        '*/orders/5551234/transactions.json' => Http::response(['transactions' => []], 200),
    ]);

    $order = shopifyOrderForActions();

    $result = app(ShopifyAdapter::class)->refund($order, new RefundData);

    expect($result->success)->toBeFalse();
    expect($order->fresh()->status)->toBe(Order::STATUS_NEW);
});

test('cancel marks the order cancelled', function () {
    Http::fake([
        '*/orders/5551234/cancel.json' => Http::response(['order' => ['id' => 5551234]], 200),
    ]);

    $order = shopifyOrderForActions();

    $result = app(ShopifyAdapter::class)->cancel($order, 'Out of stock');

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/orders/5551234/cancel.json')
        && ($request['reason'] ?? null) === 'other');
});

test('a failed cancel API call reports failure without changing local status', function () {
    Http::fake([
        '*/orders/5551234/cancel.json' => Http::response(['errors' => 'already fulfilled'], 422),
    ]);

    $order = shopifyOrderForActions();

    $result = app(ShopifyAdapter::class)->cancel($order, null);

    expect($result->success)->toBeFalse();
    expect($order->fresh()->status)->toBe(Order::STATUS_NEW);
});
