<?php

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\PollTikTokOrdersJob;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\TikTokAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.tiktok_shop.app_key' => 'test-app-key',
        'services.tiktok_shop.app_secret' => 'test-app-secret',
    ]);
});

function tiktokConnectionForPolling(array $overrides = []): StoreConnection
{
    return StoreConnection::factory()->create(array_merge([
        'platform' => StoreConnection::PLATFORM_TIKTOK,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => [
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_at' => now()->addHour()->toIso8601String(),
            'shop_id' => 'shop-1',
            'shop_cipher' => 'cipher-abc',
        ],
    ], $overrides));
}

function runTikTokPollJob(int $connectionId): void
{
    (new PollTikTokOrdersJob($connectionId))->handle(app(IngestOrderAction::class), app(TikTokAdapter::class));
}

test('the poller pages through page_token and ingests real orders', function () {
    $connection = tiktokConnectionForPolling();

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/order/202309/orders/search')) {
            $body = $request->data();

            if (($body['page_token'] ?? null) === 'page-2-token') {
                return Http::response(['data' => ['orders' => [[
                    'id' => '222',
                    'create_time' => 1752570000,
                    'order_status' => 'AWAITING_SHIPMENT',
                    'payment' => ['currency' => 'USD', 'total_amount' => '10.00'],
                ]]]], 200);
            }

            return Http::response(['data' => [
                'orders' => [[
                    'id' => '111',
                    'create_time' => 1752570000,
                    'order_status' => 'AWAITING_SHIPMENT',
                    'payment' => ['currency' => 'USD', 'total_amount' => '10.00'],
                    'line_items' => [
                        ['id' => 'item-1', 'seller_sku' => 'SKU-1', 'product_name' => 'Widget', 'quantity' => 1, 'sale_price' => '10.00'],
                    ],
                ]],
                'next_page_token' => 'page-2-token',
            ]], 200);
        }

        return Http::response([], 404);
    });

    runTikTokPollJob($connection->id);

    expect(Order::query()->where('connection_id', $connection->id)->where('external_id', '111')->exists())->toBeTrue();
    expect(Order::query()->where('connection_id', $connection->id)->where('external_id', '222')->exists())->toBeTrue();

    $order = Order::query()->where('connection_id', $connection->id)->where('external_id', '111')->first();
    expect($order->items)->toHaveCount(1);
    expect($order->items[0]->sku)->toBe('SKU-1');

    expect($connection->fresh()->last_sync_at)->not->toBeNull();
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('a 401 from the order search endpoint marks the connection needs_reauth without throwing', function () {
    $connection = tiktokConnectionForPolling();

    Http::fake([
        '*/order/202309/orders/search*' => Http::response([], 401),
    ]);

    runTikTokPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
    expect(Order::query()->where('connection_id', $connection->id)->count())->toBe(0);
});

test('polling a non-tiktok or missing connection is a safe no-op', function () {
    runTikTokPollJob(999999);
})->throwsNoExceptions();
