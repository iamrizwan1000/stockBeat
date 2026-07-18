<?php

use App\Models\Order;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\TikTokAdapter;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.tiktok_shop.app_key' => 'test-app-key',
        'services.tiktok_shop.app_secret' => 'test-app-secret',
    ]);
});

function tiktokConnectionForActions(array $overrides = []): StoreConnection
{
    return StoreConnection::factory()->create(array_merge([
        'platform' => StoreConnection::PLATFORM_TIKTOK,
        'credentials' => [
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_at' => now()->addHour()->toIso8601String(),
            'shop_id' => 'shop-1',
            'shop_cipher' => 'cipher-abc',
        ],
    ], $overrides));
}

test('fulfill looks up the open package, resolves a shipping provider, ships, and marks the order shipped', function () {
    Http::fake([
        'open-api.tiktokglobalshop.com/fulfillment/202309/orders/*/packages*' => Http::response([
            'data' => ['packages' => [['id' => 'pkg-1']]],
        ], 200),
        'open-api.tiktokglobalshop.com/logistics/202309/packages/*/shipping_providers*' => Http::response([
            'data' => ['shipping_providers' => [['id' => 'prov-ups', 'name' => 'UPS'], ['id' => 'prov-usps', 'name' => 'USPS']]],
        ], 200),
        'open-api.tiktokglobalshop.com/fulfillment/202309/packages/*/ship*' => Http::response(['data' => []], 200),
    ]);

    $connection = tiktokConnectionForActions();
    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_TIKTOK,
        'external_id' => '1729000000000000000',
        'status' => Order::STATUS_UNFULFILLED,
    ]);

    $result = app(TikTokAdapter::class)->fulfill($order, new FulfillmentData('TT999', 'UPS'));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
    expect($order->fresh()->fulfillment_status)->toBe(Order::FULFILLMENT_FULFILLED);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/fulfillment/202309/packages/pkg-1/ship')
        && ($request['tracking_number'] ?? null) === 'TT999'
        && ($request['shipping_provider_id'] ?? null) === 'prov-ups');
});

test('fulfill fails cleanly when the connection needs to be reconnected', function () {
    $connection = tiktokConnectionForActions([
        'credentials' => [
            'access_token' => 'stale',
            'refresh_token' => '',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_TIKTOK,
        'external_id' => '1729000000000000000',
    ]);

    $result = app(TikTokAdapter::class)->fulfill($order, new FulfillmentData('TT999'));

    expect($result->success)->toBeFalse();
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
});

test('fulfill fails cleanly when no package is found', function () {
    Http::fake([
        'open-api.tiktokglobalshop.com/fulfillment/202309/orders/*/packages*' => Http::response(['data' => ['packages' => []]], 200),
    ]);

    $connection = tiktokConnectionForActions();
    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_TIKTOK,
        'external_id' => '1729000000000000000',
    ]);

    $result = app(TikTokAdapter::class)->fulfill($order, new FulfillmentData('TT999'));

    expect($result->success)->toBeFalse();
});

test('cancel submits a real cancel request and cancels a pre-shipment order', function () {
    Http::fake([
        'open-api.tiktokglobalshop.com/order/202309/orders/*/cancel*' => Http::response(['data' => []], 200),
    ]);

    $connection = tiktokConnectionForActions();
    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_TIKTOK,
        'external_id' => '1729000000000000000',
        'status' => Order::STATUS_UNFULFILLED,
    ]);

    $result = app(TikTokAdapter::class)->cancel($order, 'Out of stock');

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/order/202309/orders/1729000000000000000/cancel')
        && ($request['cancel_reason'] ?? null) === 'OUT_OF_STOCK');
});

test('cancel fails cleanly for an order that has already shipped', function () {
    $connection = tiktokConnectionForActions();
    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_TIKTOK,
        'external_id' => '1729000000000000000',
        'status' => Order::STATUS_SHIPPED,
    ]);

    $result = app(TikTokAdapter::class)->cancel($order, 'Too late');

    expect($result->success)->toBeFalse();
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
});

test('cancel fails cleanly when TikTok Shop rejects the request', function () {
    Http::fake([
        'open-api.tiktokglobalshop.com/order/202309/orders/*/cancel*' => Http::response([], 500),
    ]);

    $connection = tiktokConnectionForActions();
    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_TIKTOK,
        'external_id' => '1729000000000000000',
        'status' => Order::STATUS_UNFULFILLED,
    ]);

    $result = app(TikTokAdapter::class)->cancel($order, 'Out of stock');

    expect($result->success)->toBeFalse();
    expect($order->fresh()->status)->toBe(Order::STATUS_UNFULFILLED);
});

test('refund always fails cleanly — TikTok Shop has no seller-initiated refund API', function () {
    $connection = tiktokConnectionForActions();
    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_TIKTOK,
        'external_id' => '1729000000000000000',
        'total' => 49.99,
    ]);

    $result = app(TikTokAdapter::class)->refund($order, new RefundData(amount: 49.99));

    expect($result->success)->toBeFalse();
    expect($order->fresh()->status)->not->toBe(Order::STATUS_REFUNDED);
});

test('refreshAuth updates the access token and expiry on success', function () {
    Http::fake(['auth.tiktok-shops.com/api/v2/token/refresh' => Http::response([
        'data' => ['access_token' => 'new-token', 'access_token_expire_in' => 7200, 'refresh_token' => 'new-refresh'],
    ], 200)]);

    $connection = tiktokConnectionForActions([
        'credentials' => [
            'access_token' => 'old-token',
            'refresh_token' => 'refresh-abc',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    app(TikTokAdapter::class)->refreshAuth($connection);

    expect($connection->fresh()->credentials['access_token'])->toBe('new-token');
    expect($connection->fresh()->credentials['refresh_token'])->toBe('new-refresh');
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('refreshAuth marks needs_reauth when the refresh call fails', function () {
    Http::fake(['auth.tiktok-shops.com/api/v2/token/refresh' => Http::response(['message' => 'invalid_grant'], 400)]);

    $connection = tiktokConnectionForActions([
        'credentials' => [
            'access_token' => 'old-token',
            'refresh_token' => 'refresh-abc',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    app(TikTokAdapter::class)->refreshAuth($connection);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
});

test('capabilities report cancel as supported but refunds and messaging as not', function () {
    $capabilities = app(TikTokAdapter::class)->capabilities();

    expect($capabilities->realtimeOrders)->toBeTrue();
    expect($capabilities->refunds)->toBeFalse();
    expect($capabilities->cancel)->toBeTrue();
    expect($capabilities->fulfillTracking)->toBeTrue();
    expect($capabilities->messagingMode)->toBe('none');
});
