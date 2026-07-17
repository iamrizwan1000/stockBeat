<?php

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\PollShopifyOrdersJob;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\Shopify\ShopifyOrderMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function shopifyConnectionForPolling(): StoreConnection
{
    return StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_SHOPIFY,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => ['shop_domain' => 'my-test-shop.myshopify.com', 'access_token' => 'shpat_faketoken'],
    ]);
}

function runShopifyPollJob(int $connectionId): void
{
    (new PollShopifyOrdersJob($connectionId))->handle(app(ShopifyOrderMapper::class), app(IngestOrderAction::class));
}

test('the poller ingests orders returned since the last sync and updates last_sync_at', function () {
    $connection = shopifyConnectionForPolling();

    Http::fake([
        '*/orders.json*' => Http::response(['orders' => [[
            'id' => 900,
            'name' => '#900',
            'financial_status' => 'paid',
            'fulfillment_status' => null,
            'currency' => 'USD',
            'total_price' => '25.00',
            'customer' => ['first_name' => 'Sam', 'last_name' => 'Buyer', 'email' => 'sam@example.com'],
            'shipping_address' => [],
            'created_at' => '2026-07-16T10:00:00-00:00',
            'tags' => '',
            'line_items' => [],
        ]]], 200),
    ]);

    runShopifyPollJob($connection->id);

    expect(Order::query()->where('connection_id', $connection->id)->where('external_id', '900')->exists())->toBeTrue();
    expect($connection->fresh()->last_sync_at)->not->toBeNull();
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('a 401 response marks the connection needs_reauth without throwing', function () {
    $connection = shopifyConnectionForPolling();

    Http::fake(['*/orders.json*' => Http::response([], 401)]);

    runShopifyPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
});

test('a transient server error leaves the connection untouched for the next run', function () {
    $connection = shopifyConnectionForPolling();

    Http::fake(['*/orders.json*' => Http::response([], 500)]);

    runShopifyPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
    expect($connection->fresh()->last_sync_at)->toBeNull();
});

test('polling a non-shopify or missing connection is a safe no-op', function () {
    runShopifyPollJob(999999);
})->throwsNoExceptions();
