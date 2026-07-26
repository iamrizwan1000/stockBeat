<?php

use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

test('a seller can update stock on a woo product and it pushes to the real store', function () {
    Http::fake(['*/wp-json/wc/v3/products/*' => Http::response(['id' => 555], 200)]);

    $user = onboardedProductUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create([
        'team_id' => $team->id,
        'platform' => StoreConnection::PLATFORM_WOO,
        'credentials' => ['store_url' => 'https://example-shop.test', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
    ]);
    $product = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'external_id' => '555', 'stock_quantity' => 3]);

    $response = test()->putJson("/api/v1/products/{$product->id}/stock", ['quantity' => 25]);

    $response->assertOk()->assertJsonPath('data.product.stock_quantity', 25);
    expect($product->fresh()->stock_quantity)->toBe(25);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/wp-json/wc/v3/products/555')
        && ($request['stock_quantity'] ?? null) === 25);
});

test('updating stock on a channel without inventory-update support returns a clear error', function () {
    $user = onboardedProductUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create([
        'team_id' => $team->id,
        'platform' => StoreConnection::PLATFORM_ETSY,
        'credentials' => ['access_token' => '1.fake-token', 'shop_id' => 555111],
    ]);
    $product = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'stock_quantity' => 3]);

    test()->putJson("/api/v1/products/{$product->id}/stock", ['quantity' => 25])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('product');

    expect($product->fresh()->stock_quantity)->toBe(3);
});

test('a negative stock quantity is rejected', function () {
    $user = onboardedProductUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'platform' => StoreConnection::PLATFORM_WOO]);
    $product = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    test()->putJson("/api/v1/products/{$product->id}/stock", ['quantity' => -1])
        ->assertStatus(422);
});

test('a seller cannot update stock on another team\'s product', function () {
    onboardedProductUser();
    $otherTeamProduct = Product::factory()->create();

    test()->putJson("/api/v1/products/{$otherTeamProduct->id}/stock", ['quantity' => 10])
        ->assertNotFound();
});

test('a viewer role cannot update stock', function () {
    $user = onboardedProductUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'platform' => StoreConnection::PLATFORM_WOO]);
    $product = Product::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    $viewer = User::factory()->create();
    TeamMember::factory()->create([
        'team_id' => $team->id,
        'user_id' => $viewer->id,
        'role' => TeamMember::ROLE_VIEWER,
    ]);
    Sanctum::actingAs($viewer);

    test()->putJson("/api/v1/products/{$product->id}/stock", ['quantity' => 10])
        ->assertForbidden();
});
