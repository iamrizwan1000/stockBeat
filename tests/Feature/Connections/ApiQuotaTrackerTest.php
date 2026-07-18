<?php

use App\Actions\Orders\IngestOrderAction;
use App\Actions\Rules\CheckLowStockAction;
use App\Jobs\PollAmazonOrdersJob;
use App\Jobs\PollEbayInventoryJob;
use App\Jobs\PollEbayOrdersJob;
use App\Jobs\PollEtsyOrdersJob;
use App\Jobs\PollTikTokOrdersJob;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\AmazonAdapter;
use App\Support\Connections\Adapters\Ebay\EbayOrderMapper;
use App\Support\Connections\Adapters\EbayAdapter;
use App\Support\Connections\Adapters\Etsy\EtsyOrderMapper;
use App\Support\Connections\Adapters\EtsyAdapter;
use App\Support\Connections\Adapters\TikTokAdapter;
use App\Support\Connections\ApiQuotaTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * One test per hooked call site (Plan §8.7.7 gap #1), each verifying the
 * exact hook added to a real adapter/poll job — see `ApiQuotaTracker`'s own
 * docblock for the full list. Deliberately exercises the real poll jobs
 * (not the tracker in isolation), since the thing worth proving is that
 * the hook actually fires from the real call path, not just that the
 * tracker's own increment logic works.
 */
test('polling etsy increments the etsy quota counter once per real outbound call', function () {
    config(['services.etsy.keystring' => 'test-keystring']);

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_ETSY,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => ['access_token' => '1.fake-token', 'refresh_token' => 'fake-refresh', 'shop_id' => 555111, 'expires_at' => now()->addHour()->toIso8601String()],
    ]);

    Http::fake([
        'api.etsy.com/v3/application/shops/555111/receipts*' => Http::response(['results' => []], 200),
    ]);

    expect(ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_ETSY))->toBe(0);

    (new PollEtsyOrdersJob($connection->id))->handle(
        app(EtsyOrderMapper::class),
        app(IngestOrderAction::class),
        app(EtsyAdapter::class),
    );

    expect(ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_ETSY))->toBe(1);
});

test('polling ebay orders increments the ebay quota counter', function () {
    config(['services.ebay.env' => 'sandbox']);

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expires_at' => now()->addHour()->toIso8601String()],
    ]);

    Http::fake([
        'api.sandbox.ebay.com/sell/fulfillment/v1/order*' => Http::response(['orders' => []], 200),
    ]);

    (new PollEbayOrdersJob($connection->id))->handle(
        app(EbayOrderMapper::class),
        app(IngestOrderAction::class),
        app(EbayAdapter::class),
    );

    expect(ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_EBAY))->toBe(1);
});

test('polling ebay inventory increments the ebay quota counter once per page', function () {
    config(['services.ebay.env' => 'sandbox']);

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expires_at' => now()->addHour()->toIso8601String()],
    ]);

    Http::fake([
        'api.sandbox.ebay.com/sell/inventory/v1/inventory_item*' => Http::response(['total' => 0, 'inventoryItems' => []], 200),
    ]);

    (new PollEbayInventoryJob($connection->id))->handle(
        app(EbayAdapter::class),
        app(CheckLowStockAction::class),
    );

    expect(ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_EBAY))->toBe(1);
});

test('polling amazon orders increments the amazon quota counter for every signed request', function () {
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

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_AMAZON,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => [
            'access_token' => 'fake-lwa-token',
            'refresh_token' => 'fake-refresh',
            'expires_at' => now()->addHour()->toIso8601String(),
            'selling_partner_id' => 'A1B2C3',
        ],
    ]);

    $stsXml = <<<'XML'
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

    Http::fake([
        'sts.amazonaws.com/*' => Http::response($stsXml, 200, ['Content-Type' => 'text/xml']),
        '*/tokens/2021-03-01/restrictedDataToken' => Http::response(['restrictedDataToken' => 'rdt-fake'], 200),
        '*/orders/v0/orders' => Http::response(['payload' => ['Orders' => []]], 200),
    ]);

    (new PollAmazonOrdersJob($connection->id))->handle(
        app(IngestOrderAction::class),
        app(AmazonAdapter::class),
    );

    // Two calls funnel through signedRequest() here: RDT issuance
    // (createRestrictedDataToken()) and the single getOrders page (no
    // orders returned, so no per-order getOrderItems calls). STS
    // assume-role isn't counted — it's a plain AWS STS call, not an
    // SP-API data-plane call subject to Plan §7.5's token bucket.
    expect(ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_AMAZON))->toBe(2);
});

test('polling tiktok orders increments the tiktok quota counter for every signed request', function () {
    config([
        'services.tiktok_shop.app_key' => 'test-app-key',
        'services.tiktok_shop.app_secret' => 'test-app-secret',
    ]);

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_TIKTOK,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => [
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_at' => now()->addHour()->toIso8601String(),
            'shop_id' => 'shop-1',
            'shop_cipher' => 'cipher-abc',
        ],
    ]);

    Http::fake([
        '*/order/202309/orders/search' => Http::response(['data' => ['orders' => []]], 200),
    ]);

    (new PollTikTokOrdersJob($connection->id))->handle(
        app(IngestOrderAction::class),
        app(TikTokAdapter::class),
    );

    expect(ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_TIKTOK))->toBe(1);
});

test('quota counters are scoped per platform and accumulate across multiple calls', function () {
    expect(ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_ETSY))->toBe(0);

    ApiQuotaTracker::recordCall(StoreConnection::PLATFORM_ETSY);
    ApiQuotaTracker::recordCall(StoreConnection::PLATFORM_ETSY);
    ApiQuotaTracker::recordCall(StoreConnection::PLATFORM_EBAY);

    expect(ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_ETSY))->toBe(2);
    expect(ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_EBAY))->toBe(1);
    expect(ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_AMAZON))->toBe(0);
});
