<?php

use App\Actions\Rules\CheckStaleInventoryAction;
use App\Models\Product;
use App\Models\ProductStockSnapshot;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a product with no snapshot history yet does not fire', function () {
    $team = Team::factory()->create();
    Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_STALE_INVENTORY, 'controls' => ['stale_days' => 30]]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 10]);

    app(CheckStaleInventoryAction::class)->handle($product);

    expect(RuleExecution::query()->count())->toBe(0);
});

test('stock unchanged since the earliest snapshot on record, past the threshold, fires once', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_STALE_INVENTORY, 'controls' => ['stale_days' => 30]]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 10]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 10, 'recorded_at' => now()->subDays(40)]);

    app(CheckStaleInventoryAction::class)->handle($product);

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
    expect($product->fresh()->stale_stock_notified_at)->not->toBeNull();
});

test('stock that changed within the threshold window does not fire', function () {
    $team = Team::factory()->create();
    Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_STALE_INVENTORY, 'controls' => ['stale_days' => 30]]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 10]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 25, 'recorded_at' => now()->subDays(10)]);

    app(CheckStaleInventoryAction::class)->handle($product);

    expect(RuleExecution::query()->count())->toBe(0);
});

test('the last actual change is found even among later same-value snapshots', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_STALE_INVENTORY, 'controls' => ['stale_days' => 30]]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 10]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 50, 'recorded_at' => now()->subDays(40)]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 10, 'recorded_at' => now()->subDays(25)]);

    app(CheckStaleInventoryAction::class)->handle($product);

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('a product with untracked stock (null) is skipped', function () {
    $team = Team::factory()->create();
    Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_STALE_INVENTORY]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => null]);

    app(CheckStaleInventoryAction::class)->handle($product);

    expect(RuleExecution::query()->count())->toBe(0);
});

test('a still-stale product does not fire again until stock actually changes and goes stale again', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_STALE_INVENTORY, 'controls' => ['stale_days' => 30]]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 10]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 10, 'recorded_at' => now()->subDays(40)]);

    app(CheckStaleInventoryAction::class)->handle($product);
    app(CheckStaleInventoryAction::class)->handle($product->fresh());
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);

    $product->fresh()->update(['stock_quantity' => 99]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 99, 'recorded_at' => now()]);
    app(CheckStaleInventoryAction::class)->handle($product->fresh());
    expect($product->fresh()->stale_stock_notified_at)->toBeNull();
});

test('the product\'s store connection is passed through and mutes the push when muted', function () {
    $team = Team::factory()->create();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'notifications_muted' => true]);
    $rule = Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_STALE_INVENTORY, 'controls' => ['stale_days' => 30]]);
    $product = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'stock_quantity' => 10]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 10, 'recorded_at' => now()->subDays(40)]);

    app(CheckStaleInventoryAction::class)->handle($product);

    $execution = RuleExecution::query()->where('rule_id', $rule->id)->firstOrFail();
    expect($execution->actions_result[0])->toMatchArray(['type' => 'push', 'status' => 'muted_by_store']);
});
