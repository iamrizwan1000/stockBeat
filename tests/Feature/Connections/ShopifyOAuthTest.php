<?php

use App\Models\StoreConnection;
use App\Models\User;
use App\Support\Connections\OAuthState;
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

/**
 * @param  array<string, string>  $params
 */
function shopifyQueryHmac(array $params, string $secret): string
{
    unset($params['hmac'], $params['signature']);
    ksort($params);

    return hash_hmac('sha256', http_build_query($params), $secret);
}

function onboardedShopifyUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['shopify']])->assertOk();

    return $user->fresh();
}

test('starting a shopify connection returns a properly formed authorization url', function () {
    onboardedShopifyUser();

    $response = test()->postJson('/api/v1/connections/shopify/start', [
        'name' => 'My Shopify Store',
        'credentials' => ['shop_domain' => 'my-test-shop.myshopify.com'],
    ])->assertOk();

    $url = $response->json('data.authorization_url');
    expect($url)->toStartWith('https://my-test-shop.myshopify.com/admin/oauth/authorize?');
    expect($url)->toContain('client_id=test-client-id');
    expect($url)->toContain('read_orders');
});

test('a valid callback completes the connection and registers webhooks', function () {
    onboardedShopifyUser();
    Http::fake([
        'my-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_faketoken', 'scope' => 'read_orders'], 200),
        'my-test-shop.myshopify.com/admin/api/*/webhooks.json' => Http::response(['webhook' => ['id' => 555]], 201),
    ]);

    $authUrl = test()->postJson('/api/v1/connections/shopify/start', [
        'name' => 'My Shopify Store',
        'credentials' => ['shop_domain' => 'my-test-shop.myshopify.com'],
    ])->json('data.authorization_url');

    parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $startParams);
    $state = $startParams['state'];

    $callbackParams = [
        'code' => 'fake-auth-code',
        'shop' => 'my-test-shop.myshopify.com',
        'state' => $state,
        'timestamp' => (string) time(),
    ];
    $callbackParams['hmac'] = shopifyQueryHmac($callbackParams, 'test-client-secret');

    test()->get('/hooks/shopify/oauth/callback?'.http_build_query($callbackParams))->assertOk();

    $connection = StoreConnection::query()->where('platform', StoreConnection::PLATFORM_SHOPIFY)->first();
    expect($connection)->not->toBeNull();
    expect($connection->name)->toBe('My Shopify Store');
    expect($connection->status)->toBe(StoreConnection::STATUS_ACTIVE);
    expect($connection->credentials['access_token'])->toBe('shpat_faketoken');
    expect($connection->credentials['shop_domain'])->toBe('my-test-shop.myshopify.com');
    expect($connection->fingerprint)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/webhooks.json') && ($request['webhook']['topic'] ?? null) === 'orders/create');
});

test('a callback with an invalid hmac is rejected and no connection is created', function () {
    onboardedShopifyUser();

    $state = OAuthState::make(1, 'x', 'shopify', ['shop_domain' => 'my-test-shop.myshopify.com'])->encode();

    $params = [
        'code' => 'fake-code',
        'shop' => 'my-test-shop.myshopify.com',
        'state' => $state,
        'hmac' => 'not-the-right-hmac',
    ];

    test()->get('/hooks/shopify/oauth/callback?'.http_build_query($params))->assertOk();

    expect(StoreConnection::query()->count())->toBe(0);
});

test('a callback for a different shop than what was started is rejected', function () {
    $user = onboardedShopifyUser();

    $state = OAuthState::make($user->currentTeam()->id, 'My Store', 'shopify', ['shop_domain' => 'the-real-shop.myshopify.com'])->encode();

    $params = [
        'code' => 'fake-code',
        'shop' => 'a-different-shop.myshopify.com',
        'state' => $state,
    ];
    $params['hmac'] = shopifyQueryHmac($params, 'test-client-secret');

    test()->get('/hooks/shopify/oauth/callback?'.http_build_query($params))->assertOk();

    expect(StoreConnection::query()->count())->toBe(0);
});

test('a tampered state is rejected', function () {
    onboardedShopifyUser();

    $params = [
        'code' => 'fake-code',
        'shop' => 'my-test-shop.myshopify.com',
        'state' => 'not-a-real-encrypted-state',
    ];
    $params['hmac'] = shopifyQueryHmac($params, 'test-client-secret');

    test()->get('/hooks/shopify/oauth/callback?'.http_build_query($params))->assertOk();

    expect(StoreConnection::query()->count())->toBe(0);
});
