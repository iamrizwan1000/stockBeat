<?php

use App\Models\DailyStat;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

/**
 * @return array{0: User, 1: StoreConnection}
 */
function onboardedAnalyticsUser(): array
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

test('the summary endpoint requires authentication', function () {
    test()->getJson('/api/v1/analytics/summary')->assertUnauthorized();
});

test('today\'s summary is computed live from real orders, excluding test orders', function () {
    [$user, $connection] = onboardedAnalyticsUser();
    $team = $user->currentTeam();

    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'total' => 50, 'placed_at' => now()]);
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'total' => 30, 'placed_at' => now()]);
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'total' => 999, 'placed_at' => now(), 'is_test' => true]);

    test()->getJson('/api/v1/analytics/summary?range=today')
        ->assertOk()
        ->assertJsonPath('data.total.orders_count', 2)
        ->assertJsonPath('data.total.revenue', 80)
        ->assertJsonPath('data.total.aov', 40);
});

test('a free-plan team is restricted to range=today', function () {
    [$user] = onboardedAnalyticsUser();
    $user->ownedTeam->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);

    test()->getJson('/api/v1/analytics/summary?range=7d')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('range');

    test()->getJson('/api/v1/analytics/summary?range=today')->assertOk();
});

test('a 7-day summary combines historical daily_stats with live today', function () {
    [$user, $connection] = onboardedAnalyticsUser();
    $team = $user->currentTeam();

    DailyStat::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'date' => now()->subDays(2)->toDateString(),
        'orders_count' => 3,
        'revenue' => 300,
    ]);
    DailyStat::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'date' => now()->subDays(1)->toDateString(),
        'orders_count' => 2,
        'revenue' => 200,
    ]);
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'total' => 100, 'placed_at' => now()]);

    test()->getJson('/api/v1/analytics/summary?range=7d')
        ->assertOk()
        ->assertJsonPath('data.total.orders_count', 6)
        ->assertJsonPath('data.total.revenue', 600);
});

test('by_channel breaks totals down per connection', function () {
    [$user, $connectionA] = onboardedAnalyticsUser();
    $team = $user->currentTeam();
    $connectionB = StoreConnection::factory()->create(['team_id' => $team->id]);

    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connectionA->id, 'total' => 100, 'placed_at' => now()]);
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connectionB->id, 'total' => 40, 'placed_at' => now()]);

    $response = test()->getJson('/api/v1/analytics/summary?range=today')->assertOk();

    $byChannel = collect($response->json('data.by_channel'))->keyBy('connection_id');
    expect($byChannel[$connectionA->id]['revenue'])->toBe(100);
    expect($byChannel[$connectionB->id]['revenue'])->toBe(40);
});

test('goal tracking reports progress toward the best historical month for pro teams', function () {
    [$user, $connection] = onboardedAnalyticsUser();
    $team = $user->currentTeam();

    Carbon::setTestNow(Carbon::parse('2026-06-15', 'UTC'));
    DailyStat::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'date' => '2026-05-01',
        'revenue' => 1000,
    ]);

    $response = test()->getJson('/api/v1/analytics/summary?range=30d')->assertOk();

    expect($response->json('data.goal.best_month_revenue'))->toBe(1000);
    expect($response->json('data.goal.pct_of_best_month'))->toBe(0);

    Carbon::setTestNow();
});
