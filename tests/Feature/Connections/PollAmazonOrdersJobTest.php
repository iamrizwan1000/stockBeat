<?php

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\PollAmazonOrdersJob;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\AmazonAdapter;
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

function amazonConnectionForPolling(array $overrides = []): StoreConnection
{
    return StoreConnection::factory()->create(array_merge([
        'platform' => StoreConnection::PLATFORM_AMAZON,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => [
            'access_token' => 'fake-lwa-token',
            'refresh_token' => 'fake-refresh',
            'expires_at' => now()->addHour()->toIso8601String(),
            'selling_partner_id' => 'A1B2C3',
        ],
    ], $overrides));
}

function runAmazonPollJob(int $connectionId): void
{
    (new PollAmazonOrdersJob($connectionId))->handle(app(IngestOrderAction::class), app(AmazonAdapter::class));
}

function fakeStsAssumeRoleXml(): string
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

test('the poller pages through NextToken, fetches order items per order, and ingests real orders', function () {
    $connection = amazonConnectionForPolling();

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'sts.amazonaws.com')) {
            return Http::response(fakeStsAssumeRoleXml(), 200);
        }

        if (str_contains($url, '/tokens/2021-03-01/restrictedDataToken')) {
            return Http::response(['restrictedDataToken' => 'Atzr|fake-rdt'], 200);
        }

        if (str_contains($url, '/orderItems')) {
            return Http::response(['payload' => ['OrderItems' => [
                ['OrderItemId' => 'item-1', 'SellerSKU' => 'SKU-1', 'Title' => 'Widget', 'QuantityOrdered' => 1, 'ItemPrice' => ['CurrencyCode' => 'USD', 'Amount' => '10.00']],
            ]]], 200);
        }

        if (str_contains($url, '/orders/v0/orders')) {
            if (str_contains($url, 'NextToken=page-2-token')) {
                return Http::response(['payload' => [
                    'Orders' => [[
                        'AmazonOrderId' => '222-2222222-2222222',
                        'PurchaseDate' => '2026-07-15T11:00:00Z',
                        'OrderStatus' => 'Unshipped',
                        'OrderTotal' => ['CurrencyCode' => 'USD', 'Amount' => '10.00'],
                    ]],
                ]], 200);
            }

            return Http::response(['payload' => [
                'Orders' => [[
                    'AmazonOrderId' => '111-1111111-1111111',
                    'PurchaseDate' => '2026-07-15T10:00:00Z',
                    'OrderStatus' => 'Unshipped',
                    'OrderTotal' => ['CurrencyCode' => 'USD', 'Amount' => '10.00'],
                ]],
                'NextToken' => 'page-2-token',
            ]], 200);
        }

        return Http::response([], 404);
    });

    runAmazonPollJob($connection->id);

    expect(Order::query()->where('connection_id', $connection->id)->where('external_id', '111-1111111-1111111')->exists())->toBeTrue();
    expect(Order::query()->where('connection_id', $connection->id)->where('external_id', '222-2222222-2222222')->exists())->toBeTrue();

    $order = Order::query()->where('connection_id', $connection->id)->where('external_id', '111-1111111-1111111')->first();
    expect($order->items)->toHaveCount(1);
    expect($order->items[0]->sku)->toBe('SKU-1');

    expect($connection->fresh()->last_sync_at)->not->toBeNull();
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('a 401 from getOrders marks the connection needs_reauth without throwing', function () {
    $connection = amazonConnectionForPolling();

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'sts.amazonaws.com')) {
            return Http::response(fakeStsAssumeRoleXml(), 200);
        }

        if (str_contains($url, '/tokens/2021-03-01/restrictedDataToken')) {
            return Http::response(['restrictedDataToken' => 'Atzr|fake-rdt'], 200);
        }

        if (str_contains($url, '/orders/v0/orders')) {
            return Http::response([], 401);
        }

        return Http::response([], 404);
    });

    runAmazonPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
    expect(Order::query()->where('connection_id', $connection->id)->count())->toBe(0);
});

test('polling a non-amazon or missing connection is a safe no-op', function () {
    runAmazonPollJob(999999);
})->throwsNoExceptions();

test('when RDT issuance fails, orders still sync using the plain LWA token', function () {
    $connection = amazonConnectionForPolling();

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'sts.amazonaws.com')) {
            return Http::response(fakeStsAssumeRoleXml(), 200);
        }

        if (str_contains($url, '/tokens/2021-03-01/restrictedDataToken')) {
            return Http::response([], 500);
        }

        if (str_contains($url, '/orderItems')) {
            return Http::response(['payload' => ['OrderItems' => []]], 200);
        }

        if (str_contains($url, '/orders/v0/orders')) {
            return Http::response(['payload' => ['Orders' => [[
                'AmazonOrderId' => '333-3333333-3333333',
                'PurchaseDate' => '2026-07-15T10:00:00Z',
                'OrderStatus' => 'Shipped',
                'OrderTotal' => ['CurrencyCode' => 'USD', 'Amount' => '25.00'],
            ]]]], 200);
        }

        return Http::response([], 404);
    });

    runAmazonPollJob($connection->id);

    expect(Order::query()->where('connection_id', $connection->id)->where('external_id', '333-3333333-3333333')->exists())->toBeTrue();
});
