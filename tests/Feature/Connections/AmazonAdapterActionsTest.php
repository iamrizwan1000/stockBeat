<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\AmazonAdapter;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.amazon.client_id' => 'test-lwa-client-id',
        'services.amazon.client_secret' => 'test-lwa-client-secret',
        'services.amazon.app_id' => 'test-spapi-app-id',
        'services.amazon.aws_access_key_id' => 'AKIAFAKE',
        'services.amazon.aws_secret_access_key' => 'fake-secret',
        'services.amazon.aws_region' => 'us-east-1',
        'services.amazon.role_arn' => 'arn:aws:iam::123456789012:role/spapi',
        'services.amazon.region' => 'na',
        'services.amazon.marketplace_id' => 'ATVPDKIKX0DER',
    ]);
});

function fakeAssumeRoleXml(): string
{
    return <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <AssumeRoleResponse xmlns="https://sts.amazonaws.com/doc/2011-06-15/">
        <AssumeRoleResult>
            <Credentials>
                <AccessKeyId>ASIAFAKEKEY</AccessKeyId>
                <SecretAccessKey>fakeSecretKey</SecretAccessKey>
                <SessionToken>fakeSessionToken</SessionToken>
                <Expiration>2026-07-18T12:00:00Z</Expiration>
            </Credentials>
        </AssumeRoleResult>
    </AssumeRoleResponse>
    XML;
}

/**
 * Fakes the full 3-step Feeds API submission pipeline (createFeedDocument →
 * encrypted PUT upload → createFeed) plus the STS AssumeRole call every
 * signed request depends on.
 */
function fakeAmazonFeedsPipeline(): void
{
    $key = base64_encode(random_bytes(32));
    $iv = base64_encode(random_bytes(16));

    Http::fake([
        'sts.amazonaws.com*' => Http::response(fakeAssumeRoleXml(), 200),
        'sellingpartnerapi-na.amazon.com/feeds/2021-06-30/documents' => Http::response([
            'feedDocumentId' => 'doc-1',
            'url' => 'https://s3.example.com/upload/doc-1',
            'encryptionDetails' => ['standard' => 'AES', 'key' => $key, 'initializationVector' => $iv],
        ], 201),
        's3.example.com/*' => Http::response('', 200),
        'sellingpartnerapi-na.amazon.com/feeds/2021-06-30/feeds' => Http::response(['feedId' => 'feed-1'], 202),
    ]);
}

function amazonConnectionForActions(array $overrides = []): StoreConnection
{
    return StoreConnection::factory()->create(array_merge([
        'platform' => StoreConnection::PLATFORM_AMAZON,
        'credentials' => [
            'access_token' => 'fake-lwa-token',
            'refresh_token' => 'fake-refresh',
            'expires_at' => now()->addHour()->toIso8601String(),
            'selling_partner_id' => 'A1B2C3',
        ],
    ], $overrides));
}

test('fulfill submits the real feeds pipeline and marks the order shipped', function () {
    fakeAmazonFeedsPipeline();

    $connection = amazonConnectionForActions();
    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_AMAZON,
        'external_id' => '111-2223334-5556667',
        'status' => Order::STATUS_UNFULFILLED,
    ]);

    $result = app(AmazonAdapter::class)->fulfill($order, new FulfillmentData('1Z999', 'UPS'));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
    expect($order->fresh()->fulfillment_status)->toBe(Order::FULFILLMENT_FULFILLED);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/feeds/2021-06-30/documents')
        && $request->hasHeader('Authorization')
        && str_contains((string) $request->header('Authorization')[0], 'AWS4-HMAC-SHA256'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/feeds/2021-06-30/feeds')
        && ($request['feedType'] ?? null) === 'POST_ORDER_FULFILLMENT_DATA'
        && ($request['inputFeedDocumentId'] ?? null) === 'doc-1');

    Http::assertSent(fn ($request) => str_contains($request->url(), 's3.example.com')
        && str_contains($request->body(), 'AmazonOrderID') === false); // body is AES-encrypted, not plaintext XML
});

test('fulfill fails cleanly when the connection needs to be reconnected', function () {
    $connection = amazonConnectionForActions([
        'credentials' => [
            'access_token' => 'stale',
            'refresh_token' => '',
            'expires_at' => now()->subMinute()->toIso8601String(),
            'selling_partner_id' => 'A1B2C3',
        ],
    ]);

    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_AMAZON,
        'external_id' => '111-2223334-5556667',
    ]);

    $result = app(AmazonAdapter::class)->fulfill($order, new FulfillmentData('1Z999'));

    expect($result->success)->toBeFalse();
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
});

test('fulfill fails cleanly when Amazon rejects the feed document creation', function () {
    Http::fake([
        'sts.amazonaws.com*' => Http::response(fakeAssumeRoleXml(), 200),
        'sellingpartnerapi-na.amazon.com/feeds/2021-06-30/documents' => Http::response([], 500),
    ]);

    $connection = amazonConnectionForActions();
    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_AMAZON,
        'external_id' => '111-2223334-5556667',
    ]);

    $result = app(AmazonAdapter::class)->fulfill($order, new FulfillmentData('1Z999'));

    expect($result->success)->toBeFalse();
    expect($order->fresh()->status)->toBe(Order::STATUS_NEW);
});

test('refund submits an order-adjustment feed and marks the order refunded', function () {
    fakeAmazonFeedsPipeline();

    $connection = amazonConnectionForActions();
    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_AMAZON,
        'external_id' => '111-2223334-5556667',
        'total' => 100.00,
        'currency' => 'USD',
    ]);

    $result = app(AmazonAdapter::class)->refund($order, new RefundData(amount: 100.00, reason: 'not as described'));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_REFUNDED);
    expect($order->fresh()->payment_status)->toBe(Order::PAYMENT_REFUNDED);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/feeds/2021-06-30/feeds')
        && ($request['feedType'] ?? null) === 'POST_ORDER_ADJUSTMENT_DATA');
});

test('a partial refund amount marks the order only partially refunded', function () {
    fakeAmazonFeedsPipeline();

    $connection = amazonConnectionForActions();
    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_AMAZON,
        'external_id' => '111-2223334-5556667',
        'total' => 100.00,
    ]);

    $result = app(AmazonAdapter::class)->refund($order, new RefundData(amount: 20.00));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->payment_status)->toBe(Order::PAYMENT_PARTIALLY_REFUNDED);
});

test('cancel submits an order-acknowledgement feed for each item and cancels the order', function () {
    fakeAmazonFeedsPipeline();

    $connection = amazonConnectionForActions();
    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_AMAZON,
        'external_id' => '111-2223334-5556667',
        'status' => Order::STATUS_UNFULFILLED,
    ]);
    OrderItem::factory()->create(['order_id' => $order->id, 'external_id' => 'item-1']);

    $result = app(AmazonAdapter::class)->cancel($order, 'Out of stock');

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/feeds/2021-06-30/feeds')
        && ($request['feedType'] ?? null) === 'POST_ORDER_ACKNOWLEDGEMENT_DATA');
});

test('cancel fails cleanly for an order that has already shipped', function () {
    $connection = amazonConnectionForActions();
    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_AMAZON,
        'external_id' => '111-2223334-5556667',
        'status' => Order::STATUS_SHIPPED,
    ]);

    $result = app(AmazonAdapter::class)->cancel($order, 'Too late');

    expect($result->success)->toBeFalse();
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
});

test('refreshAuth updates the access token and expiry on success', function () {
    Http::fake(['api.amazon.com/auth/o2/token' => Http::response([
        'access_token' => 'new-token',
        'expires_in' => 3600,
    ], 200)]);

    $connection = amazonConnectionForActions(['credentials' => [
        'access_token' => 'old-token',
        'refresh_token' => 'refresh-abc',
        'expires_at' => now()->subMinute()->toIso8601String(),
        'selling_partner_id' => 'A1B2C3',
    ]]);

    app(AmazonAdapter::class)->refreshAuth($connection);

    expect($connection->fresh()->credentials['access_token'])->toBe('new-token');
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('refreshAuth marks needs_reauth when the refresh call fails', function () {
    Http::fake(['api.amazon.com/auth/o2/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $connection = amazonConnectionForActions(['credentials' => [
        'access_token' => 'old-token',
        'refresh_token' => 'refresh-abc',
        'expires_at' => now()->subMinute()->toIso8601String(),
        'selling_partner_id' => 'A1B2C3',
    ]]);

    app(AmazonAdapter::class)->refreshAuth($connection);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
});

test('capabilities report cancel and refunds as supported but not realtime orders', function () {
    $capabilities = app(AmazonAdapter::class)->capabilities();

    expect($capabilities->realtimeOrders)->toBeFalse();
    expect($capabilities->refunds)->toBeTrue();
    expect($capabilities->cancel)->toBeTrue();
    expect($capabilities->fulfillTracking)->toBeTrue();
});
