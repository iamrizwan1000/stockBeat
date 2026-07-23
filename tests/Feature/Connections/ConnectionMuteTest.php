<?php

use App\Models\StoreConnection;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedUserForMuteTest(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

test('muting a connection requires authentication', function () {
    $connection = StoreConnection::factory()->create();

    test()->patchJson("/api/v1/connections/{$connection->id}", ['notifications_muted' => true])
        ->assertUnauthorized();
});

test('a connection defaults to unmuted', function () {
    $connection = StoreConnection::factory()->create();

    expect($connection->notifications_muted)->toBeFalse();
});

test('an owner can mute and unmute a connection', function () {
    $user = onboardedUserForMuteTest();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id]);

    test()->patchJson("/api/v1/connections/{$connection->id}", ['notifications_muted' => true])
        ->assertOk()
        ->assertJsonPath('data.connection.notifications_muted', true);

    expect($connection->fresh()->notifications_muted)->toBeTrue();

    test()->patchJson("/api/v1/connections/{$connection->id}", ['notifications_muted' => false])
        ->assertOk()
        ->assertJsonPath('data.connection.notifications_muted', false);

    expect($connection->fresh()->notifications_muted)->toBeFalse();
});

test('muting does not change status or syncing', function () {
    $user = onboardedUserForMuteTest();
    $connection = StoreConnection::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'status' => StoreConnection::STATUS_ACTIVE,
    ]);

    test()->patchJson("/api/v1/connections/{$connection->id}", ['notifications_muted' => true])->assertOk();

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('notifications_muted is required and must be boolean', function () {
    $user = onboardedUserForMuteTest();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id]);

    test()->patchJson("/api/v1/connections/{$connection->id}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('notifications_muted');

    test()->patchJson("/api/v1/connections/{$connection->id}", ['notifications_muted' => 'yes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('notifications_muted');
});

test('a viewer cannot mute a connection', function () {
    $owner = onboardedUserForMuteTest();
    $connection = StoreConnection::factory()->create(['team_id' => $owner->ownedTeam->id]);

    $viewer = User::factory()->create();
    TeamMember::factory()->create([
        'team_id' => $owner->ownedTeam->id,
        'user_id' => $viewer->id,
        'role' => TeamMember::ROLE_VIEWER,
    ]);
    Sanctum::actingAs($viewer);

    test()->patchJson("/api/v1/connections/{$connection->id}", ['notifications_muted' => true])
        ->assertForbidden();

    expect($connection->fresh()->notifications_muted)->toBeFalse();
});

test('a team cannot mute another team\'s connection', function () {
    onboardedUserForMuteTest();
    $otherConnection = StoreConnection::factory()->create();

    test()->patchJson("/api/v1/connections/{$otherConnection->id}", ['notifications_muted' => true])
        ->assertNotFound();
});
