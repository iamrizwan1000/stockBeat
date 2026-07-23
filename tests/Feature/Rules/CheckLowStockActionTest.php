<?php

use App\Actions\Rules\CheckLowStockAction;
use App\Models\Product;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a product at or below the threshold fires the low_stock rule once', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_LOW_STOCK,
        'controls' => ['low_stock_threshold' => 5],
    ]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 3]);

    app(CheckLowStockAction::class)->handle($product);

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
    expect($product->fresh()->low_stock_notified_at)->not->toBeNull();
});

test('a product above the threshold does not fire', function () {
    $team = Team::factory()->create();
    Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_LOW_STOCK,
        'controls' => ['low_stock_threshold' => 5],
    ]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 50]);

    app(CheckLowStockAction::class)->handle($product);

    expect(RuleExecution::query()->count())->toBe(0);
});

test('a product with untracked stock (null) is skipped', function () {
    $team = Team::factory()->create();
    Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_LOW_STOCK]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => null]);

    app(CheckLowStockAction::class)->handle($product);

    expect(RuleExecution::query()->count())->toBe(0);
});

test('a still-low product does not fire again until restocked and re-dropped', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_LOW_STOCK,
        'controls' => ['low_stock_threshold' => 5],
    ]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 3]);

    app(CheckLowStockAction::class)->handle($product);
    app(CheckLowStockAction::class)->handle($product->fresh());
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);

    $product->fresh()->update(['stock_quantity' => 20]);
    app(CheckLowStockAction::class)->handle($product->fresh());
    expect($product->fresh()->low_stock_notified_at)->toBeNull();

    $product->fresh()->update(['stock_quantity' => 2]);
    app(CheckLowStockAction::class)->handle($product->fresh());

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(2);
});

test('the product\'s store connection is passed through and mutes the push when muted', function () {
    $team = Team::factory()->create();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'notifications_muted' => true]);
    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_LOW_STOCK,
        'controls' => ['low_stock_threshold' => 5],
    ]);
    $product = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'stock_quantity' => 3]);

    app(CheckLowStockAction::class)->handle($product);

    $execution = RuleExecution::query()->where('rule_id', $rule->id)->firstOrFail();
    expect($execution->actions_result[0])->toMatchArray(['type' => 'push', 'status' => 'muted_by_store']);
});
