<?php

use App\Models\Order;
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

function onboardedUserWithConnection(): array
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    $user = $user->fresh();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id]);

    return [$user, $connection];
}

test('the feed requires authentication', function () {
    test()->getJson('/api/v1/orders')->assertUnauthorized();
});

test('the feed only shows the caller\'s team orders and excludes test orders', function () {
    [$userA, $connectionA] = onboardedUserWithConnection();
    Order::factory()->create(['team_id' => $userA->ownedTeam->id, 'connection_id' => $connectionA->id]);
    Order::factory()->create(['team_id' => $userA->ownedTeam->id, 'connection_id' => $connectionA->id, 'is_test' => true]);

    [$userB, $connectionB] = onboardedUserWithConnection();
    Order::factory()->create(['team_id' => $userB->ownedTeam->id, 'connection_id' => $connectionB->id]);

    Sanctum::actingAs($userA);
    test()->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data.orders');
});

test('the feed filters by status and value range', function () {
    [$user, $connection] = onboardedUserWithConnection();

    Order::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id, 'status' => Order::STATUS_SHIPPED, 'total' => 20]);
    Order::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id, 'status' => Order::STATUS_NEW, 'total' => 200]);

    test()->getJson('/api/v1/orders?status=shipped')->assertOk()->assertJsonCount(1, 'data.orders');
    test()->getJson('/api/v1/orders?value_min=100')->assertOk()->assertJsonCount(1, 'data.orders');
});

test('the feed search matches order number, customer, and item sku', function () {
    [$user, $connection] = onboardedUserWithConnection();

    $order = Order::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'connection_id' => $connection->id,
        'order_number' => '#5001',
        'customer_name' => 'Alex Buyer',
    ]);
    $order->items()->create(['sku' => 'FIND-ME', 'title' => 'Findable Widget', 'qty' => 1, 'price' => 10]);

    Order::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id, 'order_number' => '#9999']);

    test()->getJson('/api/v1/orders?q=5001')->assertOk()->assertJsonCount(1, 'data.orders');
    test()->getJson('/api/v1/orders?q=Alex')->assertOk()->assertJsonCount(1, 'data.orders');
    test()->getJson('/api/v1/orders?q=FIND-ME')->assertOk()->assertJsonCount(1, 'data.orders');
});

test('a free-plan team only sees orders within its history_days window', function () {
    [$user, $connection] = onboardedUserWithConnection();
    $user->ownedTeam->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);

    Order::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id, 'placed_at' => now()->subDays(2)]);
    Order::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id, 'placed_at' => now()->subDays(30)]);

    test()->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data.orders');
});

test('a pro-trial team sees the full history window', function () {
    [$user, $connection] = onboardedUserWithConnection();

    Order::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id, 'placed_at' => now()->subDays(2)]);
    Order::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id, 'placed_at' => now()->subDays(30)]);

    test()->getJson('/api/v1/orders')->assertOk()->assertJsonCount(2, 'data.orders');
});

test('order detail is scoped to the owning team', function () {
    [$userA, $connectionA] = onboardedUserWithConnection();
    $order = Order::factory()->create(['team_id' => $userA->ownedTeam->id, 'connection_id' => $connectionA->id]);

    [$userB] = onboardedUserWithConnection();
    test()->getJson("/api/v1/orders/{$order->id}")->assertNotFound();

    Sanctum::actingAs($userA);
    test()->getJson("/api/v1/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.order.id', $order->id);
});

test('a note can be added to an order and tags can be updated', function () {
    [$user, $connection] = onboardedUserWithConnection();
    $order = Order::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id]);

    test()->postJson("/api/v1/orders/{$order->id}/notes", ['body' => 'Called the customer.'])
        ->assertCreated()
        ->assertJsonPath('data.note.body', 'Called the customer.');

    test()->postJson("/api/v1/orders/{$order->id}/tags", ['tags' => ['vip', 'fragile']])
        ->assertOk()
        ->assertJsonPath('data.order.tags', ['vip', 'fragile']);

    expect($order->fresh()->notes()->count())->toBe(1);
});

test('snoozing an order removes it from the default feed, and unsnoozing brings it back', function () {
    [$user, $connection] = onboardedUserWithConnection();
    $order = Order::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id]);

    test()->postJson("/api/v1/orders/{$order->id}/snooze", ['until' => now()->addDay()->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('data.order.snoozed_until', fn ($v) => $v !== null);

    test()->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data.orders');
    test()->getJson('/api/v1/orders?include_snoozed=1')->assertOk()->assertJsonCount(1, 'data.orders');

    test()->postJson("/api/v1/orders/{$order->id}/snooze", ['until' => null])
        ->assertOk()
        ->assertJsonPath('data.order.snoozed_until', null);

    test()->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data.orders');
});

test('snoozing in the past is rejected', function () {
    [$user, $connection] = onboardedUserWithConnection();
    $order = Order::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id]);

    test()->postJson("/api/v1/orders/{$order->id}/snooze", ['until' => now()->subDay()->toIso8601String()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('until');
});

test('a previously-expired snooze no longer hides the order', function () {
    [$user, $connection] = onboardedUserWithConnection();
    Order::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'connection_id' => $connection->id,
        'snoozed_until' => now()->subHour(),
    ]);

    test()->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data.orders');
});

test('ship-by countdown reflects hours remaining and flags urgency', function () {
    [$user, $connection] = onboardedUserWithConnection();
    $urgentOrder = Order::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'connection_id' => $connection->id,
        'ship_by_at' => now()->addHours(5),
    ]);
    $comfortableOrder = Order::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'connection_id' => $connection->id,
        'ship_by_at' => now()->addDays(3),
    ]);

    $urgentResponse = test()->getJson("/api/v1/orders/{$urgentOrder->id}")->assertOk();
    expect($urgentResponse->json('data.order.ship_by_hours_remaining'))->toBeGreaterThan(0)->toBeLessThanOrEqual(5.1);
    expect($urgentResponse->json('data.order.is_ship_by_urgent'))->toBeTrue();

    $comfortableResponse = test()->getJson("/api/v1/orders/{$comfortableOrder->id}")->assertOk();
    expect($comfortableResponse->json('data.order.is_ship_by_urgent'))->toBeFalse();
});
