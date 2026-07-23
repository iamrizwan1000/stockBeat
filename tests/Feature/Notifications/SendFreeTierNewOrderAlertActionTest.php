<?php

use App\Actions\Notifications\SendFreeTierNewOrderAlertAction;
use App\Models\Device;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Plan;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\Team;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;

uses(RefreshDatabase::class);

beforeEach(function () {
    test()->seed(PlanSeeder::class);
});

function freeTierTeamForAlerts(): Team
{
    // No subscription row at all resolves to Free (`ResolveEntitlementsAction`'s
    // `$subscription?->effectivePlanKey() ?? Plan::FREE`), same as a team whose
    // trial has never been granted.
    return Team::factory()->create();
}

function paidTierTeamForAlerts(string $planKey = Plan::PRO): Team
{
    $team = Team::factory()->create();

    Subscription::factory()->create([
        'team_id' => $team->id,
        'status' => Subscription::STATUS_ACTIVE,
        'plan_key' => $planKey,
    ]);

    return $team;
}

test('a free-tier team gets a locked teaser push for a high-value order', function () {
    $team = freeTierTeamForAlerts();
    Device::factory()->create(['user_id' => $team->owner_id, 'push_token' => 'tok']);

    $order = Order::factory()->create(['team_id' => $team->id, 'total' => 250, 'total_base_currency' => 250]);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')
        ->once()
        ->withArgs(fn ($message) => $message->jsonSerialize()['notification']['body'] === 'High-value order 🔒 — upgrade for instant details & custom alerts.'
            && $message->jsonSerialize()['notification']['title'] === 'High-value order')
        ->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    $status = app(SendFreeTierNewOrderAlertAction::class)->handle($order);

    expect($status)->toBe('sent');
});

test('a free-tier team gets real order details for a non-high-value order', function () {
    $team = freeTierTeamForAlerts();
    Device::factory()->create(['user_id' => $team->owner_id, 'push_token' => 'tok']);

    $order = Order::factory()->create([
        'team_id' => $team->id,
        'order_number' => '#42',
        'currency' => 'USD',
        'total' => 50,
        'total_base_currency' => 50,
    ]);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')
        ->once()
        ->withArgs(function ($message) {
            $payload = $message->jsonSerialize()['notification'];

            return $payload['title'] === 'New order' && str_contains($payload['body'], '#42');
        })
        ->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    $status = app(SendFreeTierNewOrderAlertAction::class)->handle($order);

    expect($status)->toBe('sent');
});

test('a free-tier team\'s preset alert is muted when its one store is muted', function () {
    $team = freeTierTeamForAlerts();
    Device::factory()->create(['user_id' => $team->owner_id, 'push_token' => 'tok']);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'notifications_muted' => true]);

    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'total' => 50, 'total_base_currency' => 50]);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('send');
    app()->instance(Messaging::class, $messaging);

    $status = app(SendFreeTierNewOrderAlertAction::class)->handle($order);

    expect($status)->toBe('muted_by_store');
});

test('the preset alert stamps platform and trigger onto the Notification row', function () {
    $team = freeTierTeamForAlerts();
    Device::factory()->create(['user_id' => $team->owner_id, 'push_token' => 'tok']);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'platform' => StoreConnection::PLATFORM_WOO]);
    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'order_number' => '#7', 'currency' => 'USD', 'total' => 50, 'total_base_currency' => 50]);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->once()->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    app(SendFreeTierNewOrderAlertAction::class)->handle($order);

    $notification = Notification::query()->where('user_id', $team->owner_id)->firstOrFail();
    expect($notification->data)->toMatchArray(['platform' => 'woo', 'trigger' => 'new_order']);
});

test('a paid-tier team never gets the free-tier preset alert, high-value or not', function () {
    $team = paidTierTeamForAlerts(Plan::PREMIUM);
    Device::factory()->create(['user_id' => $team->owner_id, 'push_token' => 'tok']);

    $order = Order::factory()->create(['team_id' => $team->id, 'total' => 250, 'total_base_currency' => 250]);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('send');
    app()->instance(Messaging::class, $messaging);

    $status = app(SendFreeTierNewOrderAlertAction::class)->handle($order);

    expect($status)->toBe('not_free_tier');
});
