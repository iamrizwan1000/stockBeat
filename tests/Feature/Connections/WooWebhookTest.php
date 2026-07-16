<?php

use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

/**
 * @return array<string, mixed>
 */
function wooOrderWebhookPayload(): array
{
    return [
        'id' => 727,
        'number' => '727',
        'status' => 'processing',
        'currency' => 'USD',
        'date_created_gmt' => '2026-07-16T10:00:00',
        'total' => '59.98',
        'billing' => ['first_name' => 'Jane', 'last_name' => 'Buyer', 'email' => 'jane@example.com'],
        'shipping' => ['address_1' => '123 Main St', 'city' => 'Sydney', 'country' => 'AU'],
        'line_items' => [
            ['id' => 315, 'name' => 'Blue Widget', 'sku' => 'WIDGET-BLUE', 'quantity' => 2, 'total' => '39.98'],
        ],
    ];
}

function connectedWooStore(): StoreConnection
{
    Http::fake([
        '*/wp-json/wc/v3/orders*' => Http::response([], 200),
        '*/wp-json/wc/v3/webhooks*' => Http::response(['id' => 123], 200),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['woo']])->assertOk();

    $connectionId = test()->postJson('/api/v1/connections/woo/start', [
        'name' => 'My Woo Store',
        'credentials' => [
            'store_url' => 'https://example-shop.test',
            'consumer_key' => 'ck_x',
            'consumer_secret' => 'cs_x',
        ],
    ])->json('data.connection.id');

    return StoreConnection::query()->find($connectionId);
}

/**
 * @param  array<string, mixed>  $payload
 */
function wooSignatureFor(array $payload, string $secret): string
{
    return base64_encode(hash_hmac('sha256', json_encode($payload), $secret, true));
}

test('a validly signed order.created webhook ingests the order', function () {
    $connection = connectedWooStore();
    $secret = $connection->credentials['webhook_secret'];
    $payload = wooOrderWebhookPayload();

    test()->withHeaders([
        'X-WC-Webhook-Topic' => 'order.created',
        'X-WC-Webhook-Signature' => wooSignatureFor($payload, $secret),
    ])->postJson("/hooks/woo/{$connection->id}", $payload)->assertOk();

    $order = Order::query()->where('connection_id', $connection->id)->where('external_id', '727')->first();
    expect($order)->not->toBeNull();
    expect($order->order_number)->toBe('#727');
});

test('an invalid signature is rejected and nothing is ingested', function () {
    $connection = connectedWooStore();
    $payload = wooOrderWebhookPayload();

    test()->withHeaders([
        'X-WC-Webhook-Topic' => 'order.created',
        'X-WC-Webhook-Signature' => 'not-the-right-signature',
    ])->postJson("/hooks/woo/{$connection->id}", $payload)->assertUnauthorized();

    expect(Order::query()->where('connection_id', $connection->id)->count())->toBe(0);
});

test('a missing signature header is rejected', function () {
    $connection = connectedWooStore();

    test()->postJson("/hooks/woo/{$connection->id}", wooOrderWebhookPayload())->assertUnauthorized();
});

test('duplicate webhook deliveries are idempotent', function () {
    $connection = connectedWooStore();
    $secret = $connection->credentials['webhook_secret'];
    $payload = wooOrderWebhookPayload();
    $headers = [
        'X-WC-Webhook-Topic' => 'order.created',
        'X-WC-Webhook-Signature' => wooSignatureFor($payload, $secret),
    ];

    test()->withHeaders($headers)->postJson("/hooks/woo/{$connection->id}", $payload)->assertOk();
    test()->withHeaders($headers)->postJson("/hooks/woo/{$connection->id}", $payload)->assertOk();
    test()->withHeaders($headers)->postJson("/hooks/woo/{$connection->id}", $payload)->assertOk();

    expect(Order::query()->where('connection_id', $connection->id)->count())->toBe(1);
});

test('order.deleted marks the local order cancelled', function () {
    $connection = connectedWooStore();
    $secret = $connection->credentials['webhook_secret'];
    $createdPayload = wooOrderWebhookPayload();

    test()->withHeaders([
        'X-WC-Webhook-Topic' => 'order.created',
        'X-WC-Webhook-Signature' => wooSignatureFor($createdPayload, $secret),
    ])->postJson("/hooks/woo/{$connection->id}", $createdPayload)->assertOk();

    $deletedPayload = ['id' => 727];

    test()->withHeaders([
        'X-WC-Webhook-Topic' => 'order.deleted',
        'X-WC-Webhook-Signature' => wooSignatureFor($deletedPayload, $secret),
    ])->postJson("/hooks/woo/{$connection->id}", $deletedPayload)->assertOk();

    $order = Order::query()->where('connection_id', $connection->id)->where('external_id', '727')->first();
    expect($order->status)->toBe(Order::STATUS_CANCELLED);
});

test('a webhook for the wrong platform connection is rejected', function () {
    $connection = connectedWooStore();
    $connection->update(['platform' => StoreConnection::PLATFORM_SHOPIFY]);

    test()->postJson("/hooks/woo/{$connection->id}", wooOrderWebhookPayload())->assertNotFound();
});
