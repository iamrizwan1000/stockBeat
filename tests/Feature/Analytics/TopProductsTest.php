<?php

use App\Models\Order;
use App\Models\Plan;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

/**
 * @return array{0: User, 1: StoreConnection}
 */
function onboardedProductsUser(): array
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
        'timezone' => 'UTC',
    ])->assertOk();

    $user = $user->fresh();
    $connection = StoreConnection::factory()->create(['team_id' => $user->currentTeam()->id]);

    return [$user, $connection];
}

test('top products requires authentication', function () {
    test()->getJson('/api/v1/analytics/products')->assertUnauthorized();
});

test('products are ranked by revenue and exclude test orders', function () {
    [$user, $connection] = onboardedProductsUser();
    $team = $user->currentTeam();

    $orderA = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => now()]);
    $orderA->items()->create(['sku' => 'WIDGET-A', 'title' => 'Widget A', 'qty' => 2, 'price' => 100]);

    $orderB = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => now()]);
    $orderB->items()->create(['sku' => 'WIDGET-B', 'title' => 'Widget B', 'qty' => 1, 'price' => 10]);

    $testOrder = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => now(), 'is_test' => true]);
    $testOrder->items()->create(['sku' => 'WIDGET-C', 'title' => 'Widget C', 'qty' => 99, 'price' => 999]);

    $response = test()->getJson('/api/v1/analytics/products?range=today')->assertOk();

    $products = $response->json('data.products');
    expect($products)->toHaveCount(2);
    expect($products[0]['sku'])->toBe('WIDGET-A');
    expect($products[0]['units'])->toBe(2);
    expect($products[0]['revenue'])->toBe(200);
});

test('a free-plan team is restricted to range=today for products too', function () {
    [$user] = onboardedProductsUser();
    $user->ownedTeam->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);

    test()->getJson('/api/v1/analytics/products?range=30d')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('range');
});

test('a starter-plan team can view 7d but not 30d for products', function () {
    [$user] = onboardedProductsUser();
    $user->ownedTeam->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::STARTER]);

    test()->getJson('/api/v1/analytics/products?range=7d')->assertOk();

    test()->getJson('/api/v1/analytics/products?range=30d')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('range');
});
