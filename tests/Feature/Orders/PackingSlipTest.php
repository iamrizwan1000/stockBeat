<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreConnection;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedSellerWithOrder(): array
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    $user = $user->fresh();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id]);
    $order = Order::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id]);
    OrderItem::factory()->create(['order_id' => $order->id]);

    return [$user, $order];
}

test('the packing slip endpoint requires authentication', function () {
    $order = Order::factory()->create();

    test()->getJson("/api/v1/orders/{$order->id}/packing-slip")->assertUnauthorized();
});

test('a team member can download their own order\'s packing slip as a PDF', function () {
    [, $order] = onboardedSellerWithOrder();

    $response = test()->get("/api/v1/orders/{$order->id}/packing-slip");

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('a packing slip for another team\'s order is not found', function () {
    [, $order] = onboardedSellerWithOrder();

    $other = User::factory()->create();
    Sanctum::actingAs($other);
    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Other Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    test()->get("/api/v1/orders/{$order->id}/packing-slip")->assertNotFound();
});
