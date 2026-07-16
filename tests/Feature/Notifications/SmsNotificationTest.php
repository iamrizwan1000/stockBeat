<?php

use App\Actions\Notifications\SendSmsNotificationAction;
use App\Models\SmsLedger;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('sms is refused for insufficient credit and nothing is debited', function () {
    $team = Team::factory()->create();

    $status = app(SendSmsNotificationAction::class)->handle($team);

    expect($status)->toBe('insufficient_credit');
    expect(SmsLedger::query()->where('team_id', $team->id)->count())->toBe(0);
});

test('sms with available credit is honestly reported as not yet available, and credit is untouched', function () {
    $team = Team::factory()->create();
    SmsLedger::factory()->create(['team_id' => $team->id, 'delta' => 100, 'balance_after' => 100]);

    $status = app(SendSmsNotificationAction::class)->handle($team);

    expect($status)->toBe('not_yet_available');
    expect(SmsLedger::query()->where('team_id', $team->id)->count())->toBe(1);
});
