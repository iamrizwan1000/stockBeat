<?php

use App\Actions\Rules\CheckBackInStockAction;
use App\Actions\Rules\CheckLowStockAction;
use App\Actions\Rules\CheckStaleInventoryAction;
use App\Jobs\PollShopifyProductsJob;
use App\Models\Product;
use App\Models\ProductStockSnapshot;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\ShopifyAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Contract\Messaging;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Same requirement as PollWooProductsJobTest — the check actions funnel
    // through RuleEvaluationAction -> DispatchRuleActionsAction, which
    // eagerly resolves the real Firebase Messaging client even when no
    // rule ends up firing.
    app()->instance(Messaging::class, Mockery::mock(Messaging::class));
});

function pollShopifyProducts(int $connectionId): void
{
    (new PollShopifyProductsJob($connectionId))->handle(
        app(CheckLowStockAction::class),
        app(CheckStaleInventoryAction::class),
        app(CheckBackInStockAction::class),
        app(ShopifyAdapter::class),
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function shopifyConnectionForProductPolling(array $overrides = []): StoreConnection
{
    return StoreConnection::factory()->create(array_merge([
        'platform' => StoreConnection::PLATFORM_SHOPIFY,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => [
            'shop_domain' => 'my-test-shop.myshopify.com',
            'access_token' => 'shpat_faketoken',
            'expires_at' => now()->addDay()->toIso8601String(),
        ],
    ], $overrides));
}

test('the poller upserts one product row per variant and clears stock_quantity when stock is unmanaged', function () {
    $connection = shopifyConnectionForProductPolling();

    Http::fake([
        '*/products.json*' => Http::response(['products' => [
            [
                'id' => 1,
                'title' => 'Widget',
                'variants' => [
                    ['id' => 101, 'sku' => 'SKU-1', 'inventory_management' => 'shopify', 'inventory_quantity' => 2],
                    ['id' => 102, 'sku' => 'SKU-1-XL', 'inventory_management' => null, 'inventory_quantity' => null],
                ],
            ],
        ]], 200),
    ]);

    pollShopifyProducts($connection->id);

    $managed = Product::query()->where('connection_id', $connection->id)->where('external_id', '101')->first();
    $unmanaged = Product::query()->where('connection_id', $connection->id)->where('external_id', '102')->first();

    expect($managed->stock_quantity)->toBe(2);
    expect($managed->sku)->toBe('SKU-1');
    expect($managed->title)->toBe('Widget');
    expect($unmanaged->stock_quantity)->toBeNull();

    expect(ProductStockSnapshot::query()->where('product_id', $managed->id)->count())->toBe(1);
    expect(ProductStockSnapshot::query()->where('product_id', $managed->id)->value('stock_quantity'))->toBe(2);
    expect(ProductStockSnapshot::query()->where('product_id', $unmanaged->id)->count())->toBe(0);
});

test('the poller triggers a low_stock rule end to end when a variant is at or below threshold', function () {
    $connection = shopifyConnectionForProductPolling();
    $rule = Rule::factory()->create([
        'team_id' => $connection->team_id,
        'trigger' => Rule::TRIGGER_LOW_STOCK,
        'controls' => ['low_stock_threshold' => 5],
    ]);

    Http::fake([
        '*/products.json*' => Http::response(['products' => [
            ['id' => 1, 'title' => 'Widget', 'variants' => [
                ['id' => 101, 'sku' => 'SKU-1', 'inventory_management' => 'shopify', 'inventory_quantity' => 1],
            ]],
        ]], 200),
    ]);

    pollShopifyProducts($connection->id);

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('the poller triggers a back_in_stock rule end to end when a snapshotted variant goes from zero to positive', function () {
    $connection = shopifyConnectionForProductPolling();
    $rule = Rule::factory()->create([
        'team_id' => $connection->team_id,
        'trigger' => Rule::TRIGGER_BACK_IN_STOCK,
    ]);

    // Http::fake() with the same URL pattern registered twice does not
    // override the first call — use fakeSequence so the second poll
    // actually gets the restocked payload (same gotcha as WooCommerce's
    // equivalent test).
    Http::fakeSequence('*/products.json*')
        ->push(['products' => [['id' => 1, 'title' => 'Widget', 'variants' => [
            ['id' => 101, 'sku' => 'SKU-1', 'inventory_management' => 'shopify', 'inventory_quantity' => 0],
        ]]]], 200)
        ->push(['products' => [['id' => 1, 'title' => 'Widget', 'variants' => [
            ['id' => 101, 'sku' => 'SKU-1', 'inventory_management' => 'shopify', 'inventory_quantity' => 10],
        ]]]], 200);

    pollShopifyProducts($connection->id);
    pollShopifyProducts($connection->id);

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('pagination follows the Link header across multiple pages', function () {
    $connection = shopifyConnectionForProductPolling();
    $nextUrl = 'https://my-test-shop.myshopify.com/admin/api/2026-07/products.json?page_info=abc123&limit=100';

    Http::fake([
        $nextUrl => Http::response(['products' => [
            ['id' => 2, 'title' => 'Gadget', 'variants' => [
                ['id' => 202, 'sku' => 'SKU-2', 'inventory_management' => 'shopify', 'inventory_quantity' => 5],
            ]],
        ]], 200),
        '*/products.json*' => Http::response(
            ['products' => [
                ['id' => 1, 'title' => 'Widget', 'variants' => [
                    ['id' => 101, 'sku' => 'SKU-1', 'inventory_management' => 'shopify', 'inventory_quantity' => 3],
                ]],
            ]],
            200,
            ['Link' => "<{$nextUrl}>; rel=\"next\""],
        ),
    ]);

    pollShopifyProducts($connection->id);

    expect(Product::query()->where('connection_id', $connection->id)->where('external_id', '101')->exists())->toBeTrue();
    expect(Product::query()->where('connection_id', $connection->id)->where('external_id', '202')->exists())->toBeTrue();
});

test('an expired token with no refresh token goes straight to needs_reauth without an API call', function () {
    $connection = shopifyConnectionForProductPolling([
        'credentials' => [
            'shop_domain' => 'my-test-shop.myshopify.com',
            'access_token' => 'shpat_faketoken',
        ],
    ]);

    pollShopifyProducts($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
    Http::assertNothingSent();
});

test('polling a non-shopify or missing connection is a safe no-op', function () {
    pollShopifyProducts(999999);
})->throwsNoExceptions();
