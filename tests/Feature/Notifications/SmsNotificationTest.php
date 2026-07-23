<?php

use App\Actions\Notifications\SendSmsNotificationAction;
use App\Models\SmsLedger;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('sms is refused for insufficient credit and nothing is debited', function () {
    $team = Team::factory()->create();
    $recipient = User::factory()->create(['phone' => '+15551234567']);

    $status = app(SendSmsNotificationAction::class)->handle($team, $recipient, 'Hello');

    expect($status)->toBe('insufficient_credit');
    expect(SmsLedger::query()->where('team_id', $team->id)->count())->toBe(0);
});

test('sms is skipped when the recipient has no phone number, and credit is untouched', function () {
    $team = Team::factory()->create();
    SmsLedger::factory()->create(['team_id' => $team->id, 'delta' => 100, 'balance_after' => 100]);
    $recipient = User::factory()->create(['phone' => null]);

    $status = app(SendSmsNotificationAction::class)->handle($team, $recipient, 'Hello');

    expect($status)->toBe('no_phone_number');
    expect(SmsLedger::currentBalance($team->id))->toBe(100);
});

test('sms with available credit but no Twilio credentials configured is honestly reported as not yet available', function () {
    config(['services.twilio.account_sid' => null, 'services.twilio.auth_token' => null]);

    $team = Team::factory()->create();
    SmsLedger::factory()->create(['team_id' => $team->id, 'delta' => 100, 'balance_after' => 100]);
    $recipient = User::factory()->create(['phone' => '+15551234567']);

    $status = app(SendSmsNotificationAction::class)->handle($team, $recipient, 'Hello');

    expect($status)->toBe('not_yet_available');
    expect(SmsLedger::currentBalance($team->id))->toBe(100);
});

test('a real send debits exactly one credit', function () {
    config([
        'services.twilio.account_sid' => 'AC_test',
        'services.twilio.auth_token' => 'token_test',
        'services.twilio.messaging_service_sid' => 'MG_test',
    ]);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);

    $team = Team::factory()->create();
    SmsLedger::factory()->create(['team_id' => $team->id, 'delta' => 100, 'balance_after' => 100]);
    $recipient = User::factory()->create(['phone' => '+15551234567']);

    $status = app(SendSmsNotificationAction::class)->handle($team, $recipient, 'Your order shipped!');

    expect($status)->toBe('sent');
    expect(SmsLedger::currentBalance($team->id))->toBe(99);

    Http::assertSent(fn ($request) => $request['To'] === '+15551234567' && $request['Body'] === 'Your order shipped!');
});

test('sms is muted when its store connection has notifications muted, and credit is untouched', function () {
    $team = Team::factory()->create();
    SmsLedger::factory()->create(['team_id' => $team->id, 'delta' => 100, 'balance_after' => 100]);
    $recipient = User::factory()->create(['phone' => '+15551234567']);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'notifications_muted' => true]);

    $status = app(SendSmsNotificationAction::class)->handle($team, $recipient, 'Hello', $connection);

    expect($status)->toBe('muted_by_store');
    expect(SmsLedger::currentBalance($team->id))->toBe(100);
});

test('a failed Twilio response does not debit credit', function () {
    config([
        'services.twilio.account_sid' => 'AC_test',
        'services.twilio.auth_token' => 'token_test',
        'services.twilio.messaging_service_sid' => 'MG_test',
    ]);
    Http::fake(['api.twilio.com/*' => Http::response(['message' => 'invalid number'], 400)]);

    $team = Team::factory()->create();
    SmsLedger::factory()->create(['team_id' => $team->id, 'delta' => 100, 'balance_after' => 100]);
    $recipient = User::factory()->create(['phone' => '+15551234567']);

    $status = app(SendSmsNotificationAction::class)->handle($team, $recipient, 'Hello');

    expect($status)->toBe('failed');
    expect(SmsLedger::currentBalance($team->id))->toBe(100);
});
