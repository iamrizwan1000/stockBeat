<?php

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\PollEbayOrdersJob;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\Ebay\EbayOrderMapper;
use App\Support\Connections\Adapters\EbayAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ebay.env' => 'sandbox']);
});

function ebayConnectionForPolling(array $overrides = []): StoreConnection
{
    return StoreConnection::factory()->create(array_merge([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expires_at' => now()->addHour()->toIso8601String()],
    ], $overrides));
}

function runEbayPollJob(int $connectionId): void
{
    (new PollEbayOrdersJob($connectionId))->handle(app(EbayOrderMapper::class), app(IngestOrderAction::class), app(EbayAdapter::class));
}

test('the poller ingests orders and updates last_sync_at', function () {
    $connection = ebayConnectionForPolling();

    Http::fake([
        'api.sandbox.ebay.com/sell/fulfillment/v1/order*' => Http::response(['orders' => [[
            'orderId' => '11-22333-99999',
            'orderFulfillmentStatus' => 'NOT_STARTED',
            'orderPaymentStatus' => 'PAID',
            'pricingSummary' => ['total' => ['value' => '15.00', 'currency' => 'USD']],
            'creationDate' => '2026-07-16T10:00:00.000Z',
            'lineItems' => [],
        ]]], 200),
    ]);

    runEbayPollJob($connection->id);

    expect(Order::query()->where('connection_id', $connection->id)->where('external_id', '11-22333-99999')->exists())->toBeTrue();
    expect($connection->fresh()->last_sync_at)->not->toBeNull();
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('an expired token is refreshed before polling', function () {
    $connection = ebayConnectionForPolling(['credentials' => [
        'access_token' => 'old-token',
        'refresh_token' => 'fake-refresh',
        'expires_at' => now()->subMinute()->toIso8601String(),
    ]]);

    Http::fake([
        'api.sandbox.ebay.com/identity/v1/oauth2/token' => Http::response(['access_token' => 'refreshed-token', 'expires_in' => 7200], 200),
        'api.sandbox.ebay.com/sell/fulfillment/v1/order*' => Http::response(['orders' => []], 200),
    ]);

    runEbayPollJob($connection->id);

    expect($connection->fresh()->credentials['access_token'])->toBe('refreshed-token');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/sell/fulfillment/v1/order')
        && $request->hasHeader('Authorization', 'Bearer refreshed-token'));
});

test('a 401 response marks the connection needs_reauth without throwing', function () {
    $connection = ebayConnectionForPolling();

    Http::fake(['api.sandbox.ebay.com/sell/fulfillment/v1/order*' => Http::response([], 401)]);

    runEbayPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
});

test('polling a non-ebay or missing connection is a safe no-op', function () {
    runEbayPollJob(999999);
})->throwsNoExceptions();
