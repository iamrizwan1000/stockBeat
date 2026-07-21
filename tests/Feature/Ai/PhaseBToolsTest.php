<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Support\Ai\AssistantToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('get_profit_summary only includes items whose product has a cost price, and reports what was excluded', function () {
    $team = Team::factory()->create();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);

    $withCost = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => now()]);
    $withCost->items()->create(['sku' => 'HAS-COST', 'title' => 'Widget', 'qty' => 2, 'price' => 50]);
    Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'sku' => 'HAS-COST', 'title' => 'Widget', 'cost_price' => 20]);

    $withoutCost = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => now()]);
    $withoutCost->items()->create(['sku' => 'NO-COST', 'title' => 'Gadget', 'qty' => 1, 'price' => 30]);

    $result = app(AssistantToolRegistry::class)->call('get_profit_summary', ['range' => 'today'], $team);

    expect($result['total_revenue'])->toBe(130.0); // (2*50) + (1*30)
    expect($result['revenue_with_cost_data'])->toBe(100.0); // 2*50 only
    expect($result['estimated_cost'])->toBe(40.0); // 2*20
    expect($result['estimated_profit'])->toBe(60.0); // 100 - 40
    expect($result['units_sold_missing_cost_price'])->toBe(1);
});

test('get_profit_summary returns a null margin, not a division error, when nothing has cost data', function () {
    $team = Team::factory()->create();

    $result = app(AssistantToolRegistry::class)->call('get_profit_summary', ['range' => 'today'], $team);

    expect($result['profit_margin_pct'])->toBeNull();
    expect($result['estimated_profit'])->toBe(0.0);
});

test('get_restock_recommendations only includes products with real recent sales, sorted most urgent first', function () {
    $team = Team::factory()->create();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);

    // Sells fast, low stock — should be the most urgent.
    $urgent = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'sku' => 'FAST', 'title' => 'Fast Mover', 'stock_quantity' => 7]);
    $urgentOrder = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => now()->subDays(2)]);
    $urgentOrder->items()->create(['sku' => 'FAST', 'title' => 'Fast Mover', 'qty' => 14, 'price' => 10]); // 1/day over 14d window

    // No recent sales at all — must be excluded, not guessed.
    Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'sku' => 'STALE', 'title' => 'Stale Item', 'stock_quantity' => 2]);

    $result = app(AssistantToolRegistry::class)->call('get_restock_recommendations', [], $team);

    expect($result['recommendations'])->toHaveCount(1);
    expect($result['recommendations'][0]['sku'])->toBe('FAST');
    expect($result['recommendations'][0]['units_sold_last_14_days'])->toBe(14);
});

test('list of data tool names includes the two new Phase B tools', function () {
    expect(app(AssistantToolRegistry::class)->dataToolNames())
        ->toContain('get_profit_summary')
        ->toContain('get_restock_recommendations');
});
