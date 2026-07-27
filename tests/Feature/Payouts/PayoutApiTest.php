<?php

use App\Models\Payout;
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

function onboardedPayoutUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['shopify'],
    ])->assertOk();

    return $user->fresh();
}

test('payout endpoints require authentication', function () {
    test()->getJson('/api/v1/payouts')->assertUnauthorized();
});

test('a free-plan team is blocked from payouts with a clear upgrade message', function () {
    $user = onboardedPayoutUser();
    $user->ownedTeam->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);

    test()->getJson('/api/v1/payouts')
        ->assertForbidden()
        ->assertJsonPath('message', 'Payouts requires the Pro plan or higher.');
});

test('a pro-trial team can access payouts', function () {
    onboardedPayoutUser();

    test()->getJson('/api/v1/payouts')->assertOk();
});

test('a seller can list their own payouts, newest first', function () {
    $user = onboardedPayoutUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);

    Payout::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'arrival_date' => now()->subDays(2)]);
    Payout::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'arrival_date' => now()]);

    $response = test()->getJson('/api/v1/payouts');

    $response->assertOk()->assertJsonCount(2, 'data.payouts');
    expect($response->json('data.payouts.0.arrival_date'))->toBe(now()->toDateString());
});

test('a seller cannot see another team\'s payouts', function () {
    onboardedPayoutUser();
    $otherConnection = StoreConnection::factory()->create();
    Payout::factory()->create(['team_id' => $otherConnection->team_id, 'connection_id' => $otherConnection->id]);

    test()->getJson('/api/v1/payouts')->assertOk()->assertJsonCount(0, 'data.payouts');
});

test('payouts can be filtered by connection_id', function () {
    $user = onboardedPayoutUser();
    $team = $user->currentTeam();
    $connectionA = StoreConnection::factory()->create(['team_id' => $team->id]);
    $connectionB = StoreConnection::factory()->create(['team_id' => $team->id]);

    Payout::factory()->create(['team_id' => $team->id, 'connection_id' => $connectionA->id]);
    Payout::factory()->create(['team_id' => $team->id, 'connection_id' => $connectionB->id]);

    $response = test()->getJson("/api/v1/payouts?connection_id={$connectionA->id}");

    $response->assertOk()->assertJsonCount(1, 'data.payouts');
    expect($response->json('data.payouts.0.connection_id'))->toBe($connectionA->id);
});
