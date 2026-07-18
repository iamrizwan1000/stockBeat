<?php

use App\Actions\Rules\CheckLowStockAction;
use App\Jobs\PollEbayInventoryJob;
use App\Models\Product;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\EbayAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ebay.env' => 'sandbox']);
});

function ebayConnectionForInventoryPolling(array $overrides = []): StoreConnection
{
    return StoreConnection::factory()->create(array_merge([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expires_at' => now()->addHour()->toIso8601String()],
    ], $overrides));
}

function runEbayInventoryPollJob(int $connectionId): void
{
    (new PollEbayInventoryJob($connectionId))->handle(app(EbayAdapter::class), app(CheckLowStockAction::class));
}

test('the poller upserts products keyed by SKU from the Sell Inventory API', function () {
    $connection = ebayConnectionForInventoryPolling();

    Http::fake([
        'api.sandbox.ebay.com/sell/inventory/v1/inventory_item*' => Http::response([
            'total' => 1,
            'inventoryItems' => [
                [
                    'sku' => 'SKU-1',
                    'product' => ['title' => 'Widget'],
                    'availability' => ['shipToLocationAvailability' => ['quantity' => 2]],
                ],
            ],
        ], 200),
    ]);

    runEbayInventoryPollJob($connection->id);

    $product = Product::query()->where('connection_id', $connection->id)->where('external_id', 'SKU-1')->first();

    expect($product)->not->toBeNull();
    expect($product->sku)->toBe('SKU-1');
    expect($product->title)->toBe('Widget');
    expect($product->stock_quantity)->toBe(2);
});

test('the poller triggers a low_stock rule end to end when a product is at or below threshold', function () {
    $connection = ebayConnectionForInventoryPolling();
    $rule = Rule::factory()->create([
        'team_id' => $connection->team_id,
        'trigger' => Rule::TRIGGER_LOW_STOCK,
        'controls' => ['low_stock_threshold' => 5],
    ]);

    Http::fake([
        'api.sandbox.ebay.com/sell/inventory/v1/inventory_item*' => Http::response([
            'total' => 1,
            'inventoryItems' => [
                [
                    'sku' => 'SKU-1',
                    'product' => ['title' => 'Widget'],
                    'availability' => ['shipToLocationAvailability' => ['quantity' => 1]],
                ],
            ],
        ], 200),
    ]);

    runEbayInventoryPollJob($connection->id);

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('polling a non-ebay or missing connection is a safe no-op', function () {
    runEbayInventoryPollJob(999999);
})->throwsNoExceptions();
