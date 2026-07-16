<?php

use App\Actions\Analytics\AggregateDailyStatsAction;
use App\Actions\Analytics\SendMorningDigestAction;
use App\Models\Notification;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function teamWithOwnerForDigest(): array
{
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => TeamMember::ROLE_OWNER]);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);

    return [$team, $connection];
}

test('sends a real push naming order count, revenue, and best seller', function () {
    [$team, $connection] = teamWithOwnerForDigest();
    $yesterday = now()->subDay();

    $orderA = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => $yesterday, 'total' => 60]);
    $orderA->items()->create(['sku' => 'BEST', 'title' => 'Best Seller Widget', 'qty' => 1, 'price' => 60]);
    $orderB = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => $yesterday, 'total' => 20]);
    $orderB->items()->create(['sku' => 'MEH', 'title' => 'Ordinary Widget', 'qty' => 1, 'price' => 20]);

    app(AggregateDailyStatsAction::class)->handle($connection, $yesterday);

    $status = app(SendMorningDigestAction::class)->handle($team, $yesterday);

    expect($status)->toBe('no_devices');
    $notification = Notification::query()->where('user_id', $team->owner_id)->where('type', Notification::TYPE_DIGEST)->first();
    expect($notification)->not->toBeNull();
    expect($notification->body)->toContain('2 orders')
        ->toContain('80.00')
        ->toContain('Best Seller Widget');
});

test('no notification is sent when there were no orders yesterday', function () {
    [$team, $connection] = teamWithOwnerForDigest();
    $yesterday = now()->subDay();

    app(AggregateDailyStatsAction::class)->handle($connection, $yesterday);
    $status = app(SendMorningDigestAction::class)->handle($team, $yesterday);

    expect($status)->toBe('no_orders');
    expect(Notification::query()->where('type', Notification::TYPE_DIGEST)->count())->toBe(0);
});

test('the scheduled command only fires once per team per day, at their local 7am hour', function () {
    [$team, $connection] = teamWithOwnerForDigest();
    $yesterday = now()->subDay();
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => $yesterday, 'total' => 10]);
    app(AggregateDailyStatsAction::class)->handle($connection, $yesterday);

    Carbon::setTestNow(Carbon::today('UTC')->setTime(7, 5));
    test()->artisan('notifications:send-morning-digests')->assertExitCode(0);
    expect($team->fresh()->last_digest_sent_at)->not->toBeNull();

    $firstSentAt = $team->fresh()->last_digest_sent_at;
    test()->artisan('notifications:send-morning-digests')->assertExitCode(0);
    expect($team->fresh()->last_digest_sent_at->eq($firstSentAt))->toBeTrue();

    Carbon::setTestNow();
});

test('the scheduled command does nothing outside the 7am hour', function () {
    [$team, $connection] = teamWithOwnerForDigest();
    $yesterday = now()->subDay();
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => $yesterday, 'total' => 10]);
    app(AggregateDailyStatsAction::class)->handle($connection, $yesterday);

    Carbon::setTestNow(Carbon::today('UTC')->setTime(14, 0));
    test()->artisan('notifications:send-morning-digests')->assertExitCode(0);
    expect($team->fresh()->last_digest_sent_at)->toBeNull();

    Carbon::setTestNow();
});
