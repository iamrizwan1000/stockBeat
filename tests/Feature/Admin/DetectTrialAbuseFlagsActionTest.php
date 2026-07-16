<?php

use App\Actions\Admin\DetectTrialAbuseFlagsAction;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it flags the same fingerprint connected under more than one team', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    StoreConnection::factory()->create(['team_id' => $teamA->id, 'fingerprint' => 'shared-fp']);
    StoreConnection::factory()->create(['team_id' => $teamB->id, 'fingerprint' => 'shared-fp']);

    $flags = app(DetectTrialAbuseFlagsAction::class)->handle();

    expect($flags['shared_fingerprint_teams'])->toHaveCount(1);
    expect($flags['shared_fingerprint_teams'][0]['teams'])->toHaveCount(2);
});

test('it does not flag a fingerprint used by only one team', function () {
    $team = Team::factory()->create();
    StoreConnection::factory()->create(['team_id' => $team->id, 'fingerprint' => 'only-fp']);

    $flags = app(DetectTrialAbuseFlagsAction::class)->handle();

    expect($flags['shared_fingerprint_teams'])->toBe([]);
});

test('it does not flag two connections with the same fingerprint under the same team', function () {
    $team = Team::factory()->create();
    StoreConnection::factory()->count(2)->create(['team_id' => $team->id, 'fingerprint' => 'same-team-fp']);

    $flags = app(DetectTrialAbuseFlagsAction::class)->handle();

    expect($flags['shared_fingerprint_teams'])->toBe([]);
});

test('it flags a signup ip shared by two trial-consuming teams', function () {
    $ownerA = User::factory()->create(['signup_ip' => '203.0.113.5']);
    $teamA = Team::factory()->create(['owner_id' => $ownerA->id]);
    Subscription::factory()->create(['team_id' => $teamA->id, 'status' => Subscription::STATUS_TRIAL]);

    $ownerB = User::factory()->create(['signup_ip' => '203.0.113.5']);
    $teamB = Team::factory()->create(['owner_id' => $ownerB->id]);
    Subscription::factory()->create(['team_id' => $teamB->id, 'status' => Subscription::STATUS_TRIAL]);

    $flags = app(DetectTrialAbuseFlagsAction::class)->handle();

    expect($flags['shared_signup_ip_teams'])->toHaveCount(1);
    expect($flags['shared_signup_ip_teams'][0]['signup_ip'])->toBe('203.0.113.5');
    expect($flags['shared_signup_ip_teams'][0]['teams'])->toHaveCount(2);
});

test('it does not flag a shared signup ip when only one side has consumed a trial', function () {
    $ownerA = User::factory()->create(['signup_ip' => '203.0.113.9']);
    $teamA = Team::factory()->create(['owner_id' => $ownerA->id]);
    Subscription::factory()->create(['team_id' => $teamA->id, 'status' => Subscription::STATUS_TRIAL]);

    // Same IP, but this second user never onboarded into a subscribed team.
    User::factory()->create(['signup_ip' => '203.0.113.9']);

    $flags = app(DetectTrialAbuseFlagsAction::class)->handle();

    expect($flags['shared_signup_ip_teams'])->toBe([]);
});

test('it does not flag a signup ip used by only one team', function () {
    $owner = User::factory()->create(['signup_ip' => '203.0.113.11']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_TRIAL]);

    $flags = app(DetectTrialAbuseFlagsAction::class)->handle();

    expect($flags['shared_signup_ip_teams'])->toBe([]);
});
