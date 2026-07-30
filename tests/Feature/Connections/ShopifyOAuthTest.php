<?php

use App\Jobs\PollShopifyOrdersJob;
use App\Models\StoreConnection;
use App\Models\User;
use App\Support\Connections\OAuthState;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    config([
        'services.shopify.client_id' => 'test-client-id',
        'services.shopify.client_secret' => 'test-client-secret',
    ]);
    // Connecting now dispatches an immediate first-sync job — fake the
    // queue globally in this file so it doesn't actually execute
    // synchronously (QUEUE_CONNECTION=sync in tests) against endpoints
    // these tests don't fake, which would otherwise corrupt connection
    // state (e.g. an unfaked orders-endpoint response getting misread as
    // an auth failure and flipping status to needs_reauth).
    Queue::fake();
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

test('a valid callback completes the connection, fetches store branding, and registers webhooks', function () {
    onboardedShopifyUser();
    Http::fake([
        'my-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_faketoken', 'scope' => 'read_orders'], 200),
        'my-test-shop.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['email' => 'owner@my-test-shop.com', 'name' => 'My Test Shop']], 200),
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
    expect($connection->store_contact_email)->toBe('owner@my-test-shop.com');
    expect($connection->store_display_name)->toBe('My Test Shop');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/webhooks.json') && ($request['webhook']['topic'] ?? null) === 'orders/create');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/webhooks.json') && ($request['webhook']['topic'] ?? null) === 'inventory_levels/update');
});

test('a valid callback dispatches an immediate first-sync job rather than waiting for the next poll tick', function () {
    Queue::fake();
    onboardedShopifyUser();
    Http::fake([
        'my-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_faketoken', 'scope' => 'read_orders'], 200),
        'my-test-shop.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['email' => 'owner@my-test-shop.com', 'name' => 'My Test Shop']], 200),
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
    Queue::assertPushed(PollShopifyOrdersJob::class, fn (PollShopifyOrdersJob $job) => $job->connectionId === $connection->id);
});

test('replaying the exact same callback link does not create a second connection', function () {
    onboardedShopifyUser();
    Http::fake([
        'my-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_faketoken', 'scope' => 'read_orders'], 200),
        'my-test-shop.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['email' => 'owner@my-test-shop.com', 'name' => 'My Test Shop']], 200),
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
    $callbackUrl = '/hooks/shopify/oauth/callback?'.http_build_query($callbackParams);

    test()->get($callbackUrl)->assertOk();
    // Replaying the identical link (same nonce/state) a second time —
    // simulates a dropped connection causing a client/browser retry, or
    // the redirect firing twice.
    test()->get($callbackUrl)->assertOk();

    expect(StoreConnection::query()->where('platform', StoreConnection::PLATFORM_SHOPIFY)->count())->toBe(1);
});

test('reconnecting the same shop via a fresh oauth attempt returns the existing connection, not a duplicate', function () {
    onboardedShopifyUser();
    Http::fake([
        'my-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_faketoken', 'scope' => 'read_orders'], 200),
        'my-test-shop.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['email' => 'owner@my-test-shop.com', 'name' => 'My Test Shop']], 200),
        'my-test-shop.myshopify.com/admin/api/*/webhooks.json' => Http::response(['webhook' => ['id' => 555]], 201),
    ]);

    $completeOauth = function () {
        $authUrl = test()->postJson('/api/v1/connections/shopify/start', [
            'name' => 'My Shopify Store',
            'credentials' => ['shop_domain' => 'my-test-shop.myshopify.com'],
        ])->json('data.authorization_url');

        parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $startParams);

        $callbackParams = [
            'code' => 'fake-auth-code',
            'shop' => 'my-test-shop.myshopify.com',
            'state' => $startParams['state'],
            'timestamp' => (string) time(),
        ];
        $callbackParams['hmac'] = shopifyQueryHmac($callbackParams, 'test-client-secret');

        return test()->get('/hooks/shopify/oauth/callback?'.http_build_query($callbackParams));
    };

    $completeOauth()->assertOk();
    // A genuinely new /start call (fresh nonce, past any lock window) for
    // the identical shop_domain — the fingerprint-based existing-connection
    // check is what catches this, not the nonce lock.
    $completeOauth()->assertOk();

    expect(StoreConnection::query()->where('platform', StoreConnection::PLATFORM_SHOPIFY)->count())->toBe(1);
});

test('a valid callback still completes the connection when the shop.json branding lookup fails', function () {
    onboardedShopifyUser();
    Http::fake([
        'my-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_faketoken', 'scope' => 'read_orders'], 200),
        'my-test-shop.myshopify.com/admin/api/*/shop.json' => Http::response([], 500),
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
    expect($connection->status)->toBe(StoreConnection::STATUS_ACTIVE);
    expect($connection->store_contact_email)->toBeNull();
    expect($connection->store_display_name)->toBeNull();
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
