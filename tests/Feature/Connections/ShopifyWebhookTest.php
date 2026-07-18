<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    config([
        'services.shopify.client_id' => 'test-client-id',
        'services.shopify.client_secret' => 'test-client-secret',
    ]);
});

function connectedShopifyStore(): StoreConnection
{
    Http::fake([
        '*/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_faketoken'], 200),
        '*/webhooks.json' => Http::response(['webhook' => ['id' => 555]], 201),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['shopify']])->assertOk();

    $authUrl = test()->postJson('/api/v1/connections/shopify/start', [
        'name' => 'My Shopify Store',
        'credentials' => ['shop_domain' => 'my-test-shop.myshopify.com'],
    ])->json('data.authorization_url');

    parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $startParams);

    $callbackParams = [
        'code' => 'fake-auth-code',
        'shop' => 'my-test-shop.myshopify.com',
        'state' => $startParams['state'],
    ];
    $callbackParams['hmac'] = hash_hmac('sha256', http_build_query($callbackParams), 'test-client-secret');

    test()->get('/hooks/shopify/oauth/callback?'.http_build_query($callbackParams))->assertOk();

    return StoreConnection::query()->where('platform', StoreConnection::PLATFORM_SHOPIFY)->firstOrFail();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shopifyOrderWebhookPayload(array $overrides = []): array
{
    return array_merge([
        'id' => 5551234,
        'name' => '#1050',
        'financial_status' => 'paid',
        'fulfillment_status' => null,
        'cancelled_at' => null,
        'test' => false,
        'currency' => 'USD',
        'total_price' => '84.50',
        'email' => 'buyer@example.com',
        'customer' => ['first_name' => 'Jane', 'last_name' => 'Buyer', 'email' => 'buyer@example.com'],
        'shipping_address' => ['address1' => '123 Main St', 'city' => 'Sydney', 'country_code' => 'AU'],
        'created_at' => '2026-07-16T10:00:00-00:00',
        'tags' => '',
        'line_items' => [
            ['id' => 999, 'title' => 'Blue Widget', 'sku' => 'WIDGET-BLUE', 'quantity' => 2, 'price' => '42.25'],
        ],
    ], $overrides);
}

function shopifyBodyHmac(array $payload, string $secret): string
{
    return base64_encode(hash_hmac('sha256', json_encode($payload), $secret, true));
}

test('a validly signed orders/create webhook ingests the order', function () {
    $connection = connectedShopifyStore();
    $payload = shopifyOrderWebhookPayload();

    test()->withHeaders([
        'X-Shopify-Topic' => 'orders/create',
        'X-Shopify-Hmac-Sha256' => shopifyBodyHmac($payload, 'test-client-secret'),
    ])->postJson("/hooks/shopify/{$connection->id}", $payload)->assertOk();

    $order = Order::query()->where('connection_id', $connection->id)->where('external_id', '5551234')->first();
    expect($order)->not->toBeNull();
    expect($order->order_number)->toBe('#1050');
    expect($order->customer_email)->toBe('buyer@example.com');
    expect((float) $order->total)->toBe(84.50);
});

test('an invalid webhook signature is rejected and nothing is ingested', function () {
    $connection = connectedShopifyStore();

    test()->withHeaders([
        'X-Shopify-Topic' => 'orders/create',
        'X-Shopify-Hmac-Sha256' => 'not-the-right-signature',
    ])->postJson("/hooks/shopify/{$connection->id}", shopifyOrderWebhookPayload())->assertUnauthorized();

    expect(Order::query()->where('connection_id', $connection->id)->count())->toBe(0);
});

test('a cancelled order webhook marks the local order cancelled', function () {
    $connection = connectedShopifyStore();
    $payload = shopifyOrderWebhookPayload(['cancelled_at' => '2026-07-16T12:00:00-00:00']);

    test()->withHeaders([
        'X-Shopify-Topic' => 'orders/updated',
        'X-Shopify-Hmac-Sha256' => shopifyBodyHmac($payload, 'test-client-secret'),
    ])->postJson("/hooks/shopify/{$connection->id}", $payload)->assertOk();

    $order = Order::query()->where('connection_id', $connection->id)->where('external_id', '5551234')->first();
    expect($order->status)->toBe(Order::STATUS_CANCELLED);
});

test('a refunds/create webhook marks the referenced order refunded', function () {
    $connection = connectedShopifyStore();
    $orderPayload = shopifyOrderWebhookPayload();

    test()->withHeaders([
        'X-Shopify-Topic' => 'orders/create',
        'X-Shopify-Hmac-Sha256' => shopifyBodyHmac($orderPayload, 'test-client-secret'),
    ])->postJson("/hooks/shopify/{$connection->id}", $orderPayload)->assertOk();

    $refundPayload = ['id' => 9991, 'order_id' => 5551234];

    test()->withHeaders([
        'X-Shopify-Topic' => 'refunds/create',
        'X-Shopify-Hmac-Sha256' => shopifyBodyHmac($refundPayload, 'test-client-secret'),
    ])->postJson("/hooks/shopify/{$connection->id}", $refundPayload)->assertOk();

    $order = Order::query()->where('connection_id', $connection->id)->where('external_id', '5551234')->first();
    expect($order->status)->toBe(Order::STATUS_REFUNDED);
    expect($order->payment_status)->toBe(Order::PAYMENT_REFUNDED);
});

test('an app/uninstalled webhook disconnects the store', function () {
    $connection = connectedShopifyStore();
    $payload = ['id' => 1];

    test()->withHeaders([
        'X-Shopify-Topic' => 'app/uninstalled',
        'X-Shopify-Hmac-Sha256' => shopifyBodyHmac($payload, 'test-client-secret'),
    ])->postJson("/hooks/shopify/{$connection->id}", $payload)->assertOk();

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_DISCONNECTED);
});

test('an inventory_levels/update webhook syncs stock and fires the low_stock trigger', function () {
    $connection = connectedShopifyStore();

    $rule = Rule::factory()->create([
        'team_id' => $connection->team_id,
        'trigger' => Rule::TRIGGER_LOW_STOCK,
        'controls' => ['low_stock_threshold' => 5],
    ]);

    Http::fake([
        '*/admin/api/*/variants.json*' => Http::response([
            'variants' => [['id' => 555, 'product_id' => 777, 'sku' => 'SKU-1', 'title' => 'Default Title']],
        ], 200),
        '*/admin/api/*/products/777.json*' => Http::response([
            'product' => ['title' => 'Blue Widget'],
        ], 200),
    ]);

    $payload = ['inventory_item_id' => 998877, 'location_id' => 1, 'available' => 2];

    test()->withHeaders([
        'X-Shopify-Topic' => 'inventory_levels/update',
        'X-Shopify-Hmac-Sha256' => shopifyBodyHmac($payload, 'test-client-secret'),
    ])->postJson("/hooks/shopify/{$connection->id}", $payload)->assertOk();

    $product = Product::query()->where('connection_id', $connection->id)->where('external_id', '555')->first();
    expect($product)->not->toBeNull();
    expect($product->stock_quantity)->toBe(2);
    expect($product->title)->toBe('Blue Widget');
    expect($product->sku)->toBe('SKU-1');

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('an inventory_levels/update webhook for an unresolvable inventory item is a safe no-op', function () {
    $connection = connectedShopifyStore();

    Http::fake([
        '*/admin/api/*/variants.json*' => Http::response(['variants' => []], 200),
    ]);

    $payload = ['inventory_item_id' => 998877, 'location_id' => 1, 'available' => 2];

    test()->withHeaders([
        'X-Shopify-Topic' => 'inventory_levels/update',
        'X-Shopify-Hmac-Sha256' => shopifyBodyHmac($payload, 'test-client-secret'),
    ])->postJson("/hooks/shopify/{$connection->id}", $payload)->assertOk();

    expect(Product::query()->where('connection_id', $connection->id)->count())->toBe(0);
});

test('a webhook for the wrong platform connection is rejected', function () {
    $connection = connectedShopifyStore();
    $connection->update(['platform' => StoreConnection::PLATFORM_WOO]);

    test()->postJson("/hooks/shopify/{$connection->id}", shopifyOrderWebhookPayload())->assertNotFound();
});

test('shop/redact deletes the connection and its orders', function () {
    $connection = connectedShopifyStore();
    $orderPayload = shopifyOrderWebhookPayload();

    test()->withHeaders([
        'X-Shopify-Topic' => 'orders/create',
        'X-Shopify-Hmac-Sha256' => shopifyBodyHmac($orderPayload, 'test-client-secret'),
    ])->postJson("/hooks/shopify/{$connection->id}", $orderPayload)->assertOk();

    $gdprPayload = ['shop_id' => 1, 'shop_domain' => 'my-test-shop.myshopify.com'];

    test()->withHeaders([
        'X-Shopify-Topic' => 'shop/redact',
        'X-Shopify-Hmac-Sha256' => shopifyBodyHmac($gdprPayload, 'test-client-secret'),
    ])->postJson('/hooks/shopify/gdpr', $gdprPayload)->assertOk();

    expect(StoreConnection::query()->find($connection->id))->toBeNull();
    expect(Order::query()->where('connection_id', $connection->id)->count())->toBe(0);
});

test('customers/redact nulls out the matching order\'s customer data', function () {
    $connection = connectedShopifyStore();
    $orderPayload = shopifyOrderWebhookPayload();

    test()->withHeaders([
        'X-Shopify-Topic' => 'orders/create',
        'X-Shopify-Hmac-Sha256' => shopifyBodyHmac($orderPayload, 'test-client-secret'),
    ])->postJson("/hooks/shopify/{$connection->id}", $orderPayload)->assertOk();

    $gdprPayload = [
        'shop_domain' => 'my-test-shop.myshopify.com',
        'customer' => ['email' => 'buyer@example.com'],
        'orders_to_redact' => [5551234],
    ];

    test()->withHeaders([
        'X-Shopify-Topic' => 'customers/redact',
        'X-Shopify-Hmac-Sha256' => shopifyBodyHmac($gdprPayload, 'test-client-secret'),
    ])->postJson('/hooks/shopify/gdpr', $gdprPayload)->assertOk();

    $order = Order::query()->where('connection_id', $connection->id)->where('external_id', '5551234')->first();
    expect($order->customer_name)->toBeNull();
    expect($order->customer_email)->toBeNull();
    expect($order->shipping_address)->toBeNull();
});

test('a GDPR webhook with an invalid signature is rejected', function () {
    connectedShopifyStore();

    test()->withHeaders(['X-Shopify-Topic' => 'shop/redact', 'X-Shopify-Hmac-Sha256' => 'bad-signature'])
        ->postJson('/hooks/shopify/gdpr', ['shop_domain' => 'my-test-shop.myshopify.com'])
        ->assertUnauthorized();
});
