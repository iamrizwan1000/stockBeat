<?php

use App\Models\Order;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\EtsyAdapter;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.etsy.keystring' => 'test-keystring']);
});

function etsyOrderForActions(array $overrides = []): Order
{
    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_ETSY,
        'credentials' => ['access_token' => '1.fake-token', 'refresh_token' => 'fake-refresh', 'shop_id' => 555111, 'expires_at' => now()->addHour()->toIso8601String()],
    ]);

    return Order::factory()->create(array_merge([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_ETSY,
        'external_id' => '99887766',
        'total' => 50.00,
    ], $overrides));
}

test('fulfill posts tracking info to the receipt', function () {
    Http::fake(['api.etsy.com/v3/application/shops/555111/receipts/99887766/tracking' => Http::response(['receipt_id' => 99887766], 200)]);

    $order = etsyOrderForActions();

    $result = app(EtsyAdapter::class)->fulfill($order, new FulfillmentData('9400123456', 'USPS'));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/tracking')
        && ($request['tracking_code'] ?? null) === '9400123456'
        && ($request['carrier_name'] ?? null) === 'USPS');
});

test('a failed fulfill call reports failure without changing local status', function () {
    Http::fake(['api.etsy.com/v3/application/shops/555111/receipts/99887766/tracking' => Http::response([], 422)]);

    $order = etsyOrderForActions();

    $result = app(EtsyAdapter::class)->fulfill($order, new FulfillmentData('bad'));

    expect($result->success)->toBeFalse();
    expect($order->fresh()->status)->toBe(Order::STATUS_NEW);
});

test('refund posts an amount-based refund', function () {
    Http::fake(['api.etsy.com/v3/application/shops/555111/receipts/99887766/refunds' => Http::response(['ok' => true], 200)]);

    $order = etsyOrderForActions(['total' => 50.00]);

    $result = app(EtsyAdapter::class)->refund($order, new RefundData(amount: 50.00, reason: 'damaged'));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_REFUNDED);
    expect($order->fresh()->payment_status)->toBe(Order::PAYMENT_REFUNDED);
});

test('a partial refund marks the order partially refunded', function () {
    Http::fake(['api.etsy.com/v3/application/shops/555111/receipts/99887766/refunds' => Http::response([], 200)]);

    $order = etsyOrderForActions(['total' => 50.00]);

    $result = app(EtsyAdapter::class)->refund($order, new RefundData(amount: 10.00));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->payment_status)->toBe(Order::PAYMENT_PARTIALLY_REFUNDED);
});

test('cancel always reports failure — etsy has no direct cancel api', function () {
    $order = etsyOrderForActions();

    $result = app(EtsyAdapter::class)->cancel($order, 'changed mind');

    expect($result->success)->toBeFalse();
    expect($order->fresh()->status)->toBe(Order::STATUS_NEW);
});

test('capabilities report cancel as unsupported', function () {
    expect(app(EtsyAdapter::class)->capabilities()->cancel)->toBeFalse();
});

test('refreshAuth updates tokens on success', function () {
    Http::fake(['api.etsy.com/v3/public/oauth/token' => Http::response([
        'access_token' => '1.new-token',
        'refresh_token' => 'new-refresh',
        'expires_in' => 3600,
    ], 200)]);

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_ETSY,
        'credentials' => ['access_token' => '1.old', 'refresh_token' => 'old-refresh', 'shop_id' => 1, 'expires_at' => now()->subMinute()->toIso8601String()],
    ]);

    app(EtsyAdapter::class)->refreshAuth($connection);

    expect($connection->fresh()->credentials['access_token'])->toBe('1.new-token');
    expect($connection->fresh()->credentials['refresh_token'])->toBe('new-refresh');
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('refreshAuth marks needs_reauth when the refresh call fails', function () {
    Http::fake(['api.etsy.com/v3/public/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_ETSY,
        'credentials' => ['access_token' => '1.old', 'refresh_token' => 'old-refresh', 'shop_id' => 1, 'expires_at' => now()->subMinute()->toIso8601String()],
    ]);

    app(EtsyAdapter::class)->refreshAuth($connection);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
});
