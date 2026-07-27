<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedBulkPackingSlipsUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

test('bulk packing slips endpoint requires authentication', function () {
    test()->postJson('/api/v1/orders/bulk-packing-slips', ['ids' => [1]])->assertUnauthorized();
});

test('a team can download packing slips for several of its own orders as one PDF', function () {
    $user = onboardedBulkPackingSlipsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $orderA = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);
    $orderB = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);
    OrderItem::factory()->create(['order_id' => $orderA->id]);
    OrderItem::factory()->create(['order_id' => $orderB->id]);

    $response = test()->postJson('/api/v1/orders/bulk-packing-slips', ['ids' => [$orderA->id, $orderB->id]]);

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('a bulk packing slip request touching another team\'s order is rejected entirely', function () {
    $user = onboardedBulkPackingSlipsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $ownOrder = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);
    $otherOrder = Order::factory()->create();

    test()->postJson('/api/v1/orders/bulk-packing-slips', ['ids' => [$ownOrder->id, $otherOrder->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids');
});

test('bulk packing slips requires at least one id', function () {
    onboardedBulkPackingSlipsUser();

    test()->postJson('/api/v1/orders/bulk-packing-slips', ['ids' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids');
});

test('a free-plan team is blocked from bulk packing slips with a clear upgrade message', function () {
    $user = onboardedBulkPackingSlipsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);
    $team->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);

    test()->postJson('/api/v1/orders/bulk-packing-slips', ['ids' => [$order->id]])
        ->assertForbidden()
        ->assertJsonPath('message', 'Bulk order actions require the Starter plan or higher.');
});

test('a viewer role can still download bulk packing slips (read-only, not gated to owner/manager)', function () {
    $user = onboardedBulkPackingSlipsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    $viewer = User::factory()->create();
    TeamMember::factory()->create([
        'team_id' => $team->id,
        'user_id' => $viewer->id,
        'role' => TeamMember::ROLE_VIEWER,
    ]);
    Sanctum::actingAs($viewer);

    test()->postJson('/api/v1/orders/bulk-packing-slips', ['ids' => [$order->id]])->assertOk();
});
