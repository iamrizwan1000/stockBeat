<?php

use App\Exceptions\Connections\AdapterNotReadyException;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use App\Support\Connections\Adapters\EbayAdapter;
use App\Support\Connections\Adapters\EtsyAdapter;
use App\Support\Connections\Adapters\ShopifyAdapter;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Shopify/eBay/Etsy's authorizationUrl()/completeConnection() were NOT
 * guarded before this pass — unlike AmazonAdapter/TikTokAdapter, they'd
 * silently build a broken OAuth URL (empty client_id) instead of failing
 * cleanly when the Partner/Developer app credentials aren't configured
 * (Plan §15.2). This is the same "stub-ready, no live creds" contract
 * AmazonAdapterUnreadyTest/TikTokAdapterUnreadyTest already establish for
 * the other two platforms.
 */
test('Shopify authorizationUrl/completeConnection throw when app credentials are not configured', function () {
    config(['services.shopify.client_id' => null, 'services.shopify.client_secret' => null]);

    expect(fn () => app(ShopifyAdapter::class)->authorizationUrl(['shop_domain' => 'my-shop.myshopify.com'], 'state'))
        ->toThrow(AdapterNotReadyException::class);

    expect(fn () => app(ShopifyAdapter::class)->completeConnection(
        Team::factory()->create(),
        'My Shopify Store',
        ['shop_domain' => 'my-shop.myshopify.com'],
        'nonce',
        Request::create('/hooks/shopify/oauth/callback', 'GET', ['shop' => 'my-shop.myshopify.com', 'code' => 'x']),
    ))->toThrow(AdapterNotReadyException::class);
});

test('eBay authorizationUrl/completeConnection throw when app credentials are not configured', function () {
    config(['services.ebay.app_id' => null, 'services.ebay.cert_id' => null, 'services.ebay.ru_name' => null]);

    expect(fn () => app(EbayAdapter::class)->authorizationUrl([], 'state'))
        ->toThrow(AdapterNotReadyException::class);

    expect(fn () => app(EbayAdapter::class)->completeConnection(
        Team::factory()->create(),
        'My eBay Store',
        [],
        'nonce',
        Request::create('/hooks/ebay/oauth/callback', 'GET', ['code' => 'x']),
    ))->toThrow(AdapterNotReadyException::class);
});

test('Etsy authorizationUrl/completeConnection throw when app credentials are not configured', function () {
    config(['services.etsy.keystring' => null]);

    expect(fn () => app(EtsyAdapter::class)->authorizationUrl([], 'state'))
        ->toThrow(AdapterNotReadyException::class);

    expect(fn () => app(EtsyAdapter::class)->completeConnection(
        Team::factory()->create(),
        'My Etsy Shop',
        [],
        'nonce',
        Request::create('/hooks/etsy/oauth/callback', 'GET', ['code' => 'x']),
    ))->toThrow(AdapterNotReadyException::class);
});

test('starting a shopify/ebay/etsy connection via the API surfaces a clean 422, not a broken OAuth URL', function (string $platform, array $credentials) {
    config([
        'services.shopify.client_id' => null, 'services.shopify.client_secret' => null,
        'services.ebay.app_id' => null, 'services.ebay.cert_id' => null, 'services.ebay.ru_name' => null,
        'services.etsy.keystring' => null,
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->seed(PlanSeeder::class);

    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => [$platform]])->assertOk();

    test()->postJson("/api/v1/connections/{$platform}/start", [
        'name' => 'My Store',
        'credentials' => $credentials,
    ])->assertStatus(422);
})->with([
    'shopify' => [StoreConnection::PLATFORM_SHOPIFY, ['shop_domain' => 'my-shop.myshopify.com']],
    'ebay' => [StoreConnection::PLATFORM_EBAY, []],
    'etsy' => [StoreConnection::PLATFORM_ETSY, []],
]);
