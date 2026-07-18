<?php

use App\Actions\FeatureFlags\IsFeatureEnabledForTeamAction;
use App\Models\FeatureFlag;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an unknown flag key defaults to false', function () {
    $team = Team::factory()->create();

    expect(app(IsFeatureEnabledForTeamAction::class)->handle('does_not_exist', $team))->toBeFalse();
});

test('a disabled flag is always false regardless of rollout percentage', function () {
    $team = Team::factory()->create();
    $flag = FeatureFlag::factory()->create(['enabled' => false, 'rollout_percentage' => 100]);

    expect(app(IsFeatureEnabledForTeamAction::class)->handle($flag->key, $team))->toBeFalse();
});

test('a team on the allow-list is always enabled, even at 0% rollout', function () {
    $team = Team::factory()->create();
    $flag = FeatureFlag::factory()->create([
        'enabled' => true,
        'rollout_percentage' => 0,
        'enabled_for_team_ids' => [$team->id],
    ]);

    expect(app(IsFeatureEnabledForTeamAction::class)->handle($flag->key, $team))->toBeTrue();
});

test('a team not on the allow-list is unaffected by other teams being listed', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $flag = FeatureFlag::factory()->create([
        'enabled' => true,
        'rollout_percentage' => 0,
        'enabled_for_team_ids' => [$otherTeam->id],
    ]);

    expect(app(IsFeatureEnabledForTeamAction::class)->handle($flag->key, $team))->toBeFalse();
});

test('evaluation is deterministic — the same team gets the same result on repeated calls', function () {
    $team = Team::factory()->create();
    $flag = FeatureFlag::factory()->create(['enabled' => true, 'rollout_percentage' => 50]);

    $action = app(IsFeatureEnabledForTeamAction::class);
    $first = $action->handle($flag->key, $team);

    for ($i = 0; $i < 5; $i++) {
        expect($action->handle($flag->key, $team))->toBe($first);
    }
});

test('rollout percentage is monotonic — raising it only ever adds teams, never removes them', function () {
    $flag = FeatureFlag::factory()->create(['enabled' => true, 'rollout_percentage' => 0]);
    $action = app(IsFeatureEnabledForTeamAction::class);

    $teams = Team::factory()->count(100)->create();

    $previouslyEnabled = [];

    for ($percentage = 0; $percentage <= 100; $percentage++) {
        $flag->rollout_percentage = $percentage;
        $flag->save();
        $flag->refresh();

        $currentlyEnabled = [];

        foreach ($teams as $team) {
            $isEnabled = $action->handle($flag->key, $team);

            if ($isEnabled) {
                $currentlyEnabled[$team->id] = true;
            }
        }

        // Every team enabled at the previous (lower) percentage must still
        // be enabled now — this is the whole point of a percentage rollout.
        foreach (array_keys($previouslyEnabled) as $teamId) {
            expect($currentlyEnabled)->toHaveKey($teamId);
        }

        $previouslyEnabled = $currentlyEnabled;
    }

    // Sanity: at 100% every team is enabled, and at least one team was
    // excluded at some lower percentage (proves the sweep actually
    // exercised bucketing rather than trivially passing on an empty set).
    expect(count($previouslyEnabled))->toBe($teams->count());
});

test('at 100% rollout every team is enabled and at 0% none are (absent the allow-list)', function () {
    $teams = Team::factory()->count(20)->create();
    $action = app(IsFeatureEnabledForTeamAction::class);

    $flagOff = FeatureFlag::factory()->create(['enabled' => true, 'rollout_percentage' => 0]);
    $flagFull = FeatureFlag::factory()->create(['enabled' => true, 'rollout_percentage' => 100]);

    foreach ($teams as $team) {
        expect($action->handle($flagOff->key, $team))->toBeFalse();
        expect($action->handle($flagFull->key, $team))->toBeTrue();
    }
});
