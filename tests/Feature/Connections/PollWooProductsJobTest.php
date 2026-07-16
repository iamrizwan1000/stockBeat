<?php

use App\Actions\Rules\CheckLowStockAction;
use App\Jobs\PollWooProductsJob;
use App\Models\Product;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

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

    (new PollWooProductsJob($connection->id))->handle(app(CheckLowStockAction::class));

    $widget = Product::query()->where('connection_id', $connection->id)->where('external_id', '1')->first();
    $gadget = Product::query()->where('connection_id', $connection->id)->where('external_id', '2')->first();

    expect($widget->stock_quantity)->toBe(2);
    expect($widget->sku)->toBe('SKU-1');
    expect($gadget->stock_quantity)->toBeNull();
    expect($gadget->sku)->toBeNull();
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

    (new PollWooProductsJob($connection->id))->handle(app(CheckLowStockAction::class));

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('polling a non-woo or missing connection is a safe no-op', function () {
    (new PollWooProductsJob(999999))->handle(app(CheckLowStockAction::class));
})->throwsNoExceptions();
