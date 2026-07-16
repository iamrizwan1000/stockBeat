<?php

use App\Actions\Analytics\AggregateDailyStatsAction;
use App\Models\DailyStat;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function teamWithConnectionForAggregation(): array
{
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => TeamMember::ROLE_OWNER]);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);

    return [$team, $connection];
}

test('aggregates orders_count, revenue, and refunds for the target day, excluding test orders and other days', function () {
    [$team, $connection] = teamWithConnectionForAggregation();
    $yesterday = now()->subDay();

    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => $yesterday, 'total' => 50]);
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => $yesterday, 'total' => 30, 'payment_status' => 'refunded']);
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => $yesterday, 'total' => 999, 'is_test' => true]);
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => now()->subDays(2), 'total' => 500]);

    $stat = app(AggregateDailyStatsAction::class)->handle($connection, $yesterday);

    expect($stat->orders_count)->toBe(2);
    expect($stat->revenue)->toBe(80.0);
    expect($stat->aov)->toBe(40.0);
    expect($stat->refunds)->toBe(1);
});

test('running it twice for the same day updates the same row instead of duplicating', function () {
    [$team, $connection] = teamWithConnectionForAggregation();
    $yesterday = now()->subDay();

    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => $yesterday, 'total' => 50]);

    app(AggregateDailyStatsAction::class)->handle($connection, $yesterday);
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => $yesterday, 'total' => 25]);
    app(AggregateDailyStatsAction::class)->handle($connection, $yesterday);

    expect(DailyStat::query()->where('connection_id', $connection->id)->count())->toBe(1);
    expect(DailyStat::query()->where('connection_id', $connection->id)->first()->orders_count)->toBe(2);
});

test('the analytics:aggregate-daily command runs across all connections for a given date', function () {
    [$team, $connection] = teamWithConnectionForAggregation();
    $date = now()->subDays(3);

    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => $date, 'total' => 75]);

    test()->artisan('analytics:aggregate-daily', ['--date' => $date->toDateString()])->assertExitCode(0);

    $stat = DailyStat::query()->where('connection_id', $connection->id)->first();
    expect($stat)->not->toBeNull();
    expect($stat->revenue)->toBe(75.0);
});
