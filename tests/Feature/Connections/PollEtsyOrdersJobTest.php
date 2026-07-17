<?php

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\PollEtsyOrdersJob;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\Etsy\EtsyOrderMapper;
use App\Support\Connections\Adapters\EtsyAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.etsy.keystring' => 'test-keystring']);
});

function etsyConnectionForPolling(array $overrides = []): StoreConnection
{
    return StoreConnection::factory()->create(array_merge([
        'platform' => StoreConnection::PLATFORM_ETSY,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => ['access_token' => '1.fake-token', 'refresh_token' => 'fake-refresh', 'shop_id' => 555111, 'expires_at' => now()->addHour()->toIso8601String()],
    ], $overrides));
}

function runEtsyPollJob(int $connectionId): void
{
    (new PollEtsyOrdersJob($connectionId))->handle(app(EtsyOrderMapper::class), app(IngestOrderAction::class), app(EtsyAdapter::class));
}

test('the poller ingests receipts and updates last_sync_at', function () {
    $connection = etsyConnectionForPolling();

    Http::fake([
        'api.etsy.com/v3/application/shops/555111/receipts*' => Http::response(['results' => [[
            'receipt_id' => 700111,
            'status' => 'open',
            'was_shipped' => false,
            'was_paid' => true,
            'name' => 'Sam Buyer',
            'buyer_email' => 'sam@example.com',
            'grandtotal' => ['amount' => 1500, 'divisor' => 100, 'currency_code' => 'USD'],
            'created_timestamp' => 1752654000,
            'transactions' => [],
        ]]], 200),
    ]);

    runEtsyPollJob($connection->id);

    expect(Order::query()->where('connection_id', $connection->id)->where('external_id', '700111')->exists())->toBeTrue();
    expect($connection->fresh()->last_sync_at)->not->toBeNull();
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('an expired token is refreshed before polling', function () {
    $connection = etsyConnectionForPolling(['credentials' => [
        'access_token' => '1.old-token',
        'refresh_token' => 'fake-refresh',
        'shop_id' => 555111,
        'expires_at' => now()->subMinute()->toIso8601String(),
    ]]);

    Http::fake([
        'api.etsy.com/v3/public/oauth/token' => Http::response(['access_token' => '1.refreshed', 'refresh_token' => 'r2', 'expires_in' => 3600], 200),
        'api.etsy.com/v3/application/shops/555111/receipts*' => Http::response(['results' => []], 200),
    ]);

    runEtsyPollJob($connection->id);

    expect($connection->fresh()->credentials['access_token'])->toBe('1.refreshed');
});

test('a 401 response marks the connection needs_reauth without throwing', function () {
    $connection = etsyConnectionForPolling();

    Http::fake(['api.etsy.com/v3/application/shops/555111/receipts*' => Http::response([], 401)]);

    runEtsyPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
});

test('polling a non-etsy or missing connection is a safe no-op', function () {
    runEtsyPollJob(999999);
})->throwsNoExceptions();
