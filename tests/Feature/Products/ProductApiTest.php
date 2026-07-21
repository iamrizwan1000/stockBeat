<?php

use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\TeamMember;
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

test('cost prices can be bulk-updated in one call', function () {
    $user = onboardedProductUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $productA = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);
    $productB = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'cost_price' => 5]);

    test()->putJson('/api/v1/products/cost-prices', ['updates' => [
        ['id' => $productA->id, 'cost_price' => 3.5],
        ['id' => $productB->id, 'cost_price' => null],
    ]])
        ->assertOk()
        ->assertJsonCount(2, 'data.products');

    expect($productA->fresh()->cost_price)->toEqual(3.5);
    expect($productB->fresh()->cost_price)->toBeNull();
});

test('a bulk update touching another team\'s product is rejected entirely, nothing written', function () {
    $user = onboardedProductUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $ownProduct = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'cost_price' => 1]);
    $otherProduct = Product::factory()->create();

    test()->putJson('/api/v1/products/cost-prices', ['updates' => [
        ['id' => $ownProduct->id, 'cost_price' => 99],
        ['id' => $otherProduct->id, 'cost_price' => 99],
    ]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('updates');

    expect($ownProduct->fresh()->cost_price)->toEqual(1);
});

test('bulk update requires at least one item', function () {
    onboardedProductUser();

    test()->putJson('/api/v1/products/cost-prices', ['updates' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('updates');
});

test('a viewer role cannot bulk-update cost prices', function () {
    $user = onboardedProductUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $product = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    $viewer = User::factory()->create();
    TeamMember::factory()->create([
        'team_id' => $team->id,
        'user_id' => $viewer->id,
        'role' => TeamMember::ROLE_VIEWER,
    ]);
    Sanctum::actingAs($viewer);

    test()->putJson('/api/v1/products/cost-prices', ['updates' => [
        ['id' => $product->id, 'cost_price' => 10],
    ]])->assertForbidden();
});
