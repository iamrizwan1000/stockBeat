<?php

use App\Models\StoreConnection;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedHealthUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie Seller', 'sells_on' => ['woo']])->assertOk();

    return $user->fresh();
}

test('connection health requires authentication', function () {
    test()->getJson('/api/v1/connections/1/health')->assertUnauthorized();
});

test('a healthy, recently-synced connection reports normally', function () {
    $user = onboardedHealthUser();
    $connection = StoreConnection::factory()->create([
        'team_id' => $user->currentTeam()->id,
        'status' => StoreConnection::STATUS_ACTIVE,
        'last_sync_at' => now()->subMinutes(5),
        'webhook_status' => 'active',
    ]);

    test()->getJson("/api/v1/connections/{$connection->id}/health")
        ->assertOk()
        ->assertJsonPath('data.status', StoreConnection::STATUS_ACTIVE)
        ->assertJsonPath('data.fix_action', null)
        ->assertJsonPath('data.message', "{$connection->name} is connected and syncing normally.");
});

test('a connection needing reauth surfaces a fix action', function () {
    $user = onboardedHealthUser();
    $connection = StoreConnection::factory()->create([
        'team_id' => $user->currentTeam()->id,
        'status' => StoreConnection::STATUS_NEEDS_REAUTH,
    ]);

    test()->getJson("/api/v1/connections/{$connection->id}/health")
        ->assertOk()
        ->assertJsonPath('data.fix_action', 'reauth');
});

test('a stale sync is flagged even though the connection is otherwise active', function () {
    $user = onboardedHealthUser();
    $connection = StoreConnection::factory()->create([
        'team_id' => $user->currentTeam()->id,
        'status' => StoreConnection::STATUS_ACTIVE,
        'last_sync_at' => now()->subHours(5),
    ]);

    test()->getJson("/api/v1/connections/{$connection->id}/health")
        ->assertOk()
        ->assertJsonPath('data.fix_action', 'check_connection');
});

test('connection health is scoped to the caller\'s team', function () {
    onboardedHealthUser();
    $otherConnection = StoreConnection::factory()->create();

    test()->getJson("/api/v1/connections/{$otherConnection->id}/health")->assertNotFound();
});
