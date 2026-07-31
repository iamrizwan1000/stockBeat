<?php

use App\Actions\Rules\CheckBackInStockAction;
use App\Actions\Rules\CheckLowStockAction;
use App\Actions\Rules\CheckStaleInventoryAction;
use App\Jobs\PollWooProductsJob;
use App\Models\Product;
use App\Models\ProductStockSnapshot;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Contract\Messaging;

uses(RefreshDatabase::class);

beforeEach(function () {
    // PollWooProductsJob's check actions all funnel through RuleEvaluationAction
    // -> DispatchRuleActionsAction, which eagerly resolves the real Firebase
    // Messaging client even when no rule ends up firing — bind a mock so
    // container resolution succeeds without a real service-account credential
    // (same pattern as DetectAiInsightsActionTest).
    app()->instance(Messaging::class, Mockery::mock(Messaging::class));
});

function pollWooProducts(PollWooProductsJob $job): void
{
    $job->handle(app(CheckLowStockAction::class), app(CheckStaleInventoryAction::class), app(CheckBackInStockAction::class));
}

function wooConnectionForPolling(): StoreConnection
{
    $team = Team::factory()->create();

    return StoreConnection::query()->create([
        'team_id' => $team->id,
        'platform' => StoreConnection::PLATFORM_WOO,
        'name' => 'My Woo Store',
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => [
            'store_url' => 'https://example-shop.test',
            'consumer_key' => 'ck_x',
            'consumer_secret' => 'cs_x',
        ],
    ]);
}

test('the poller upserts products and clears stock_quantity when stock is unmanaged', function () {
    $connection = wooConnectionForPolling();

    Http::fake([
        '*/wp-json/wc/v3/products*' => Http::response([
            ['id' => 1, 'sku' => 'SKU-1', 'name' => 'Widget', 'manage_stock' => true, 'stock_quantity' => 2],
            ['id' => 2, 'sku' => '', 'name' => 'Gadget', 'manage_stock' => false, 'stock_quantity' => null],
        ], 200),
    ]);

    pollWooProducts(new PollWooProductsJob($connection->id));

    $widget = Product::query()->where('connection_id', $connection->id)->where('external_id', '1')->first();
    $gadget = Product::query()->where('connection_id', $connection->id)->where('external_id', '2')->first();

    expect($widget->stock_quantity)->toBe(2);
    expect($widget->sku)->toBe('SKU-1');
    expect($gadget->stock_quantity)->toBeNull();
    expect($gadget->sku)->toBeNull();

    // Phase B (Plan §4.12): a snapshot is recorded for managed-stock products
    // only — an unmanaged product has no meaningful stock_quantity to snapshot.
    expect(ProductStockSnapshot::query()->where('product_id', $widget->id)->count())->toBe(1);
    expect(ProductStockSnapshot::query()->where('product_id', $widget->id)->value('stock_quantity'))->toBe(2);
    expect(ProductStockSnapshot::query()->where('product_id', $gadget->id)->count())->toBe(0);
});

test('the poller captures the first image as image_url, and leaves it null when there are none', function () {
    $connection = wooConnectionForPolling();

    Http::fake([
        '*/wp-json/wc/v3/products*' => Http::response([
            ['id' => 1, 'sku' => 'SKU-1', 'name' => 'Widget', 'manage_stock' => true, 'stock_quantity' => 2, 'images' => [
                ['src' => 'https://example-shop.test/wp-content/uploads/widget-main.jpg'],
                ['src' => 'https://example-shop.test/wp-content/uploads/widget-alt.jpg'],
            ]],
            ['id' => 2, 'sku' => 'SKU-2', 'name' => 'Gadget', 'manage_stock' => false, 'stock_quantity' => null, 'images' => []],
        ], 200),
    ]);

    pollWooProducts(new PollWooProductsJob($connection->id));

    $widget = Product::query()->where('connection_id', $connection->id)->where('external_id', '1')->first();
    $gadget = Product::query()->where('connection_id', $connection->id)->where('external_id', '2')->first();

    expect($widget->image_url)->toBe('https://example-shop.test/wp-content/uploads/widget-main.jpg');
    expect($gadget->image_url)->toBeNull();
});

test('the poller triggers a low_stock rule end to end when a product is at or below threshold', function () {
    $connection = wooConnectionForPolling();
    $rule = Rule::factory()->create([
        'team_id' => $connection->team_id,
        'trigger' => Rule::TRIGGER_LOW_STOCK,
        'controls' => ['low_stock_threshold' => 5],
    ]);

    Http::fake([
        '*/wp-json/wc/v3/products*' => Http::response([
            ['id' => 1, 'sku' => 'SKU-1', 'name' => 'Widget', 'manage_stock' => true, 'stock_quantity' => 1],
        ], 200),
    ]);

    pollWooProducts(new PollWooProductsJob($connection->id));

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('the poller triggers a back_in_stock rule end to end when a snapshotted product goes from zero to positive', function () {
    $connection = wooConnectionForPolling();
    $rule = Rule::factory()->create([
        'team_id' => $connection->team_id,
        'trigger' => Rule::TRIGGER_BACK_IN_STOCK,
    ]);

    // Http::fake() with the same URL pattern registered twice does not
    // override the first call — use fakeSequence so the second poll
    // actually gets the restocked payload.
    Http::fakeSequence('*/wp-json/wc/v3/products*')
        ->push([['id' => 1, 'sku' => 'SKU-1', 'name' => 'Widget', 'manage_stock' => true, 'stock_quantity' => 0]], 200)
        ->push([['id' => 1, 'sku' => 'SKU-1', 'name' => 'Widget', 'manage_stock' => true, 'stock_quantity' => 10]], 200);

    pollWooProducts(new PollWooProductsJob($connection->id));
    pollWooProducts(new PollWooProductsJob($connection->id));

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('polling a non-woo or missing connection is a safe no-op', function () {
    pollWooProducts(new PollWooProductsJob(999999));
})->throwsNoExceptions();
