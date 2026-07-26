<?php

use App\Models\Order;
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

function onboardedCustomerUser(): array
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

test('the customer list requires authentication', function () {
    test()->getJson('/api/v1/customers')->assertUnauthorized();
});

test('a seller sees one row per customer email with correct order count and total spent', function () {
    [$user, $connection] = onboardedCustomerUser();

    Order::factory()->create([
        'team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id,
        'customer_email' => 'alex@example.com', 'customer_name' => 'Alex Chen',
        'total' => 50, 'total_base_currency' => 50, 'placed_at' => now()->subDay(),
    ]);
    Order::factory()->create([
        'team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id,
        'customer_email' => 'alex@example.com', 'customer_name' => 'Alex Chen',
        'total' => 30, 'total_base_currency' => 30, 'placed_at' => now(),
    ]);
    Order::factory()->create([
        'team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id,
        'customer_email' => 'jordan@example.com', 'customer_name' => 'Jordan Lee',
        'total' => 100, 'total_base_currency' => 100, 'placed_at' => now()->subHours(2),
    ]);

    $response = test()->getJson('/api/v1/customers');

    $response->assertOk()->assertJsonCount(2, 'data.customers');

    $alex = collect($response->json('data.customers'))->firstWhere('customer_email', 'alex@example.com');
    expect($alex['order_count'])->toBe(2);
    expect((float) $alex['total_spent'])->toEqual(80.0);
    expect($alex['customer_name'])->toBe('Alex Chen');
});

test('orders with no customer email are excluded rather than shown as an anonymous row', function () {
    [$user, $connection] = onboardedCustomerUser();

    Order::factory()->create([
        'team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id,
        'customer_email' => null,
    ]);

    test()->getJson('/api/v1/customers')->assertOk()->assertJsonCount(0, 'data.customers');
});

test('test orders are excluded from the customer list', function () {
    [$user, $connection] = onboardedCustomerUser();

    Order::factory()->create([
        'team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id,
        'customer_email' => 'alex@example.com', 'is_test' => true,
    ]);

    test()->getJson('/api/v1/customers')->assertOk()->assertJsonCount(0, 'data.customers');
});

test('total_spent is null rather than zero when no order has a resolved base-currency total', function () {
    [$user, $connection] = onboardedCustomerUser();

    Order::factory()->create([
        'team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id,
        'customer_email' => 'alex@example.com', 'total_base_currency' => null,
    ]);

    $response = test()->getJson('/api/v1/customers');

    expect($response->json('data.customers.0.total_spent'))->toBeNull();
    expect($response->json('data.customers.0.order_count'))->toBe(1);
});

test('the customer list respects the plan\'s history_days entitlement', function () {
    [$user, $connection] = onboardedCustomerUser();
    $user->ownedTeam->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);

    Order::factory()->create([
        'team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id,
        'customer_email' => 'alex@example.com', 'placed_at' => now()->subDays(30),
    ]);

    test()->getJson('/api/v1/customers')->assertOk()->assertJsonCount(0, 'data.customers');
});

test('a team member restricted to specific stores never sees customers from other connected stores', function () {
    [$user] = onboardedCustomerUser();
    $team = $user->currentTeam();
    $visibleConnection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $hiddenConnection = StoreConnection::factory()->create(['team_id' => $team->id]);

    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $visibleConnection->id, 'customer_email' => 'visible@example.com']);
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $hiddenConnection->id, 'customer_email' => 'hidden@example.com']);

    $restrictedUser = User::factory()->create();
    TeamMember::factory()->create([
        'team_id' => $team->id,
        'user_id' => $restrictedUser->id,
        'role' => TeamMember::ROLE_AGENT,
        'store_visibility' => [$visibleConnection->id],
    ]);
    Sanctum::actingAs($restrictedUser);

    $response = test()->getJson('/api/v1/customers');

    $response->assertOk()->assertJsonCount(1, 'data.customers');
    expect($response->json('data.customers.0.customer_email'))->toBe('visible@example.com');
});

test('GET /orders?customer_email= returns only that exact customer\'s orders', function () {
    [$user, $connection] = onboardedCustomerUser();

    Order::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id, 'customer_email' => 'alex@example.com']);
    Order::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id, 'customer_email' => 'jordan@example.com']);

    $response = test()->getJson('/api/v1/orders?customer_email=alex@example.com');

    $response->assertOk()->assertJsonCount(1, 'data.orders');
    expect($response->json('data.orders.0.customer_email'))->toBe('alex@example.com');
});
