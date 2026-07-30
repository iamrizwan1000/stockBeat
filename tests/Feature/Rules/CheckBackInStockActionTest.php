<?php

use App\Actions\Rules\CheckBackInStockAction;
use App\Models\Product;
use App\Models\ProductStockSnapshot;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;

uses(RefreshDatabase::class);

beforeEach(function () {
    // RuleEvaluationAction -> DispatchRuleActionsAction eagerly resolves the
    // real Firebase Messaging client even when no rule ends up firing — bind
    // a mock so container resolution succeeds without a real service-account
    // credential (same pattern as DetectAiInsightsActionTest).
    app()->instance(Messaging::class, Mockery::mock(Messaging::class));
});

test('a product with no snapshot history yet does not fire', function () {
    $team = Team::factory()->create();
    Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_BACK_IN_STOCK]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 10]);

    app(CheckBackInStockAction::class)->handle($product);

    expect(RuleExecution::query()->count())->toBe(0);
});

test('a genuine restock from zero fires once', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_BACK_IN_STOCK]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 15]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 0, 'recorded_at' => now()->subDay()]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 15, 'recorded_at' => now()]);

    app(CheckBackInStockAction::class)->handle($product);

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
    expect($product->fresh()->back_in_stock_notified_at)->not->toBeNull();
});

test('a restock from a positive quantity (not zero) does not fire', function () {
    $team = Team::factory()->create();
    Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_BACK_IN_STOCK]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 15]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 3, 'recorded_at' => now()->subDay()]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 15, 'recorded_at' => now()]);

    app(CheckBackInStockAction::class)->handle($product);

    expect(RuleExecution::query()->count())->toBe(0);
});

test('a product with untracked stock (null) is skipped', function () {
    $team = Team::factory()->create();
    Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_BACK_IN_STOCK]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => null]);

    app(CheckBackInStockAction::class)->handle($product);

    expect(RuleExecution::query()->count())->toBe(0);
});

test('a product still at zero does not fire and clears any stale notified_at', function () {
    $team = Team::factory()->create();
    Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_BACK_IN_STOCK]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 0, 'back_in_stock_notified_at' => now()->subDay()]);

    app(CheckBackInStockAction::class)->handle($product);

    expect(RuleExecution::query()->count())->toBe(0);
    expect($product->fresh()->back_in_stock_notified_at)->toBeNull();
});

test('does not fire again for the same in-stock streak, but fires again after a fresh stockout and restock', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_BACK_IN_STOCK]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 15]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 0, 'recorded_at' => now()->subDays(2)]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 15, 'recorded_at' => now()->subDay()]);

    app(CheckBackInStockAction::class)->handle($product);
    app(CheckBackInStockAction::class)->handle($product->fresh());
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);

    $product->fresh()->update(['stock_quantity' => 0]);
    app(CheckBackInStockAction::class)->handle($product->fresh());
    expect($product->fresh()->back_in_stock_notified_at)->toBeNull();

    $product->fresh()->update(['stock_quantity' => 20]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 0, 'recorded_at' => now()->subMinute()]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 20, 'recorded_at' => now()]);
    app(CheckBackInStockAction::class)->handle($product->fresh());

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(2);
});

test('two overlapping poll runs seeing the same stale in-memory product only fire once', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_BACK_IN_STOCK]);
    $product = Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 15]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 0, 'recorded_at' => now()->subDay()]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 15, 'recorded_at' => now()]);

    // Deliberately reuse the SAME in-memory $product instance for both
    // calls (not ->fresh()) — this simulates two overlapping poll runs each
    // loading the product *before* either write happened, so both see
    // `back_in_stock_notified_at` as null in memory. Only the atomic
    // `UPDATE ... WHERE back_in_stock_notified_at IS NULL` claim (not the
    // in-memory check) can stop the second call from firing again.
    $action = app(\App\Actions\Rules\CheckBackInStockAction::class);
    $action->handle($product);
    $action->handle($product);

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
    expect($product->fresh()->back_in_stock_notified_at)->not->toBeNull();
});

test('the product\'s store connection is passed through and mutes the push when muted', function () {
    $team = Team::factory()->create();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'notifications_muted' => true]);
    $rule = Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_BACK_IN_STOCK]);
    $product = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'stock_quantity' => 15]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 0, 'recorded_at' => now()->subDay()]);
    ProductStockSnapshot::factory()->create(['product_id' => $product->id, 'stock_quantity' => 15, 'recorded_at' => now()]);

    app(CheckBackInStockAction::class)->handle($product);

    $execution = RuleExecution::query()->where('rule_id', $rule->id)->firstOrFail();
    expect($execution->actions_result[0])->toMatchArray(['type' => 'push', 'status' => 'muted_by_store']);
});
