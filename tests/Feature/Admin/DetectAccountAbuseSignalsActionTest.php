<?php

use App\Actions\Admin\DetectAccountAbuseSignalsAction;
use App\Actions\Admin\GetOpsHealthSnapshotAction;
use App\Models\SmsLedger;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a team sharing a store fingerprint with another team is flagged trial-abuse suspected', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    StoreConnection::factory()->create(['team_id' => $teamA->id, 'fingerprint' => 'shared-fp']);
    StoreConnection::factory()->create(['team_id' => $teamB->id, 'fingerprint' => 'shared-fp']);

    $flags = app(DetectAccountAbuseSignalsAction::class)->handle($teamA);

    expect($flags['trial_abuse_suspected'])->toBeTrue();
});

test('a team with no shared fingerprint or signup ip is not flagged trial-abuse suspected', function () {
    $team = Team::factory()->create();
    StoreConnection::factory()->create(['team_id' => $team->id, 'fingerprint' => 'unique-fp']);

    $flags = app(DetectAccountAbuseSignalsAction::class)->handle($team);

    expect($flags['trial_abuse_suspected'])->toBeFalse();
});

test('a team over the SMS cost threshold this month is flagged high SMS cost', function () {
    $team = Team::factory()->create();
    SmsLedger::factory()->create(['team_id' => $team->id, 'reason' => SmsLedger::REASON_SEND, 'delta' => -250, 'balance_after' => 0]);

    $flags = app(DetectAccountAbuseSignalsAction::class)->handle($team);

    expect($flags['high_sms_cost'])->toBeTrue();
});

test('a team under the SMS cost threshold this month is not flagged high SMS cost', function () {
    $team = Team::factory()->create();
    SmsLedger::factory()->create(['team_id' => $team->id, 'reason' => SmsLedger::REASON_SEND, 'delta' => -5, 'balance_after' => 95]);

    $flags = app(DetectAccountAbuseSignalsAction::class)->handle($team);

    expect($flags['high_sms_cost'])->toBeFalse();
});

test('a team flagged in the Ops aggregate view is flagged identically via the per-team detector', function () {
    $flaggedTeam = Team::factory()->create(['name' => 'Flagged Co']);
    $otherTeam = Team::factory()->create();
    StoreConnection::factory()->create(['team_id' => $flaggedTeam->id, 'fingerprint' => 'consistency-fp']);
    StoreConnection::factory()->create(['team_id' => $otherTeam->id, 'fingerprint' => 'consistency-fp']);
    SmsLedger::factory()->create(['team_id' => $flaggedTeam->id, 'reason' => SmsLedger::REASON_SEND, 'delta' => -300, 'balance_after' => 0]);

    $health = app(GetOpsHealthSnapshotAction::class)->handle();
    $perTeamFlags = app(DetectAccountAbuseSignalsAction::class)->handle($flaggedTeam);

    $aggregateFingerprintTeamIds = collect($health['abuse']['shared_fingerprint_teams'])
        ->flatMap(fn (array $group) => collect($group['teams'])->pluck('team_id'));
    $aggregateHighSmsCostTeamIds = collect($health['abuse']['high_sms_cost_teams'])->pluck('team_id');

    expect($aggregateFingerprintTeamIds->contains($flaggedTeam->id))->toBeTrue();
    expect($perTeamFlags['trial_abuse_suspected'])->toBeTrue();

    expect($aggregateHighSmsCostTeamIds->contains($flaggedTeam->id))->toBeTrue();
    expect($perTeamFlags['high_sms_cost'])->toBeTrue();
});
