<?php

use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function onboardedProductUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

test('product endpoints require authentication', function () {
    test()->getJson('/api/v1/products')->assertUnauthorized();
});

test('a seller can set a cost price on their own product', function () {
    $user = onboardedProductUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $product = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    $response = test()->putJson("/api/v1/products/{$product->id}/cost-price", ['cost_price' => 12.50]);

    $response->assertOk()->assertJsonPath('data.product.cost_price', 12.5);
    expect($product->fresh()->cost_price)->toEqual(12.50);
});

test('sending a null cost price clears it rather than fabricating zero', function () {
    $user = onboardedProductUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $product = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'cost_price' => 9.99]);

    test()->putJson("/api/v1/products/{$product->id}/cost-price", ['cost_price' => null])->assertOk();

    expect($product->fresh()->cost_price)->toBeNull();
});

test('a seller cannot set a cost price on another team\'s product', function () {
    onboardedProductUser();
    $otherTeamProduct = Product::factory()->create();

    test()->putJson("/api/v1/products/{$otherTeamProduct->id}/cost-price", ['cost_price' => 5])
        ->assertNotFound();
});

test('a negative cost price is rejected', function () {
    $user = onboardedProductUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $product = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    test()->putJson("/api/v1/products/{$product->id}/cost-price", ['cost_price' => -5])
        ->assertStatus(422);
});
