<?php

use App\Models\Order;
use App\Models\StoreConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.tiktok_shop.app_key' => 'test-app-key',
        'services.tiktok_shop.app_secret' => 'test-webhook-secret',
    ]);
});

function tiktokConnectionForWebhooks(): StoreConnection
{
    return StoreConnection::factory()->create([
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
}

/**
 * @param  array<string, mixed>  $payload
 */
function tiktokWebhookSignatureHeader(string $path, array $payload, string $secret): string
{
    $body = json_encode($payload);
    $timestamp = 1752570000;
    $signature = hash_hmac('sha256', $path.$timestamp.$body, $secret);

    return "t={$timestamp},s={$signature}";
}

/**
 * @return array<string, mixed>
 */
function tiktokOrderStatusChangePayload(string $orderId = '1729000000000000000'): array
{
    return [
        'type' => 'ORDER_STATUS_CHANGE',
        'shop_id' => 'shop-1',
        'timestamp' => 1752570000,
        'data' => json_encode(['order_id' => $orderId, 'order_status' => 'AWAITING_SHIPMENT']),
    ];
}

test('a validly signed order-status-change webhook fetches and ingests the full order', function () {
    $connection = tiktokConnectionForWebhooks();
    $path = "hooks/tiktok/{$connection->id}";
    $payload = tiktokOrderStatusChangePayload();

    Http::fake([
        '*/order/202309/orders*' => Http::response(['data' => ['orders' => [[
            'id' => '1729000000000000000',
            'create_time' => 1752570000,
            'order_status' => 'AWAITING_SHIPMENT',
            'payment' => ['currency' => 'USD', 'total_amount' => '29.99'],
        ]]]], 200),
    ]);

    test()->withHeaders([
        'x-tts-signature' => tiktokWebhookSignatureHeader($path, $payload, 'test-webhook-secret'),
    ])->postJson("/{$path}", $payload)->assertOk();

    $order = Order::query()->where('connection_id', $connection->id)->where('external_id', '1729000000000000000')->first();
    expect($order)->not->toBeNull();
    expect($order->order_number)->toBe('#1729000000000000000');
});

test('an invalid signature is rejected and nothing is ingested', function () {
    $connection = tiktokConnectionForWebhooks();
    $path = "hooks/tiktok/{$connection->id}";
    $payload = tiktokOrderStatusChangePayload();

    test()->withHeaders([
        'x-tts-signature' => 't=1752570000,s=not-the-right-signature',
    ])->postJson("/{$path}", $payload)->assertUnauthorized();

    expect(Order::query()->where('connection_id', $connection->id)->count())->toBe(0);
});

test('a missing signature header is rejected', function () {
    $connection = tiktokConnectionForWebhooks();

    test()->postJson("/hooks/tiktok/{$connection->id}", tiktokOrderStatusChangePayload())->assertUnauthorized();
});

test('a webhook for the wrong platform connection is rejected', function () {
    $connection = tiktokConnectionForWebhooks();
    $connection->update(['platform' => StoreConnection::PLATFORM_SHOPIFY]);

    test()->postJson("/hooks/tiktok/{$connection->id}", tiktokOrderStatusChangePayload())->assertNotFound();
});

test('a validly signed but unrecognized event type is rejected (same as Woo/Shopify\'s null-parse convention)', function () {
    $connection = tiktokConnectionForWebhooks();
    $path = "hooks/tiktok/{$connection->id}";
    $payload = ['type' => 'PACKAGE_STATUS_CHANGE', 'data' => json_encode(['order_id' => '1729000000000000000'])];

    test()->withHeaders([
        'x-tts-signature' => tiktokWebhookSignatureHeader($path, $payload, 'test-webhook-secret'),
    ])->postJson("/{$path}", $payload)->assertUnauthorized();
});
