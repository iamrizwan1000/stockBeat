<?php

use App\Actions\Admin\NotifyCustomerOfCreditGrantAction;
use App\Mail\AdminCreditGrantMail;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\SmsLedger;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Kreait\Firebase\Contract\Messaging;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance(Messaging::class, Mockery::mock(Messaging::class));
});

test('sms notification does not touch the customer\'s own SmsLedger balance', function () {
    config([
        'services.twilio.account_sid' => 'AC_test',
        'services.twilio.auth_token' => 'token_test',
        'services.twilio.messaging_service_sid' => 'MG_test',
    ]);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);

    $admin = AdminUser::factory()->create();
    $user = User::factory()->create(['phone' => '+15551234567']);
    $team = Team::factory()->create(['owner_id' => $user->id]);
    SmsLedger::factory()->create(['team_id' => $team->id, 'delta' => 500, 'balance_after' => 500]);

    $results = app(NotifyCustomerOfCreditGrantAction::class)->handle($admin, $user, $team, ['sms'], 'SMS', 100);

    expect($results['sms'])->toBe('sent');
    expect(SmsLedger::currentBalance($team->id))->toBe(500);
    expect(SmsLedger::query()->where('team_id', $team->id)->count())->toBe(1);

    Http::assertSent(fn ($request) => $request['To'] === '+15551234567');
});

test('push notification bypasses the recipient\'s mute preference and quiet hours', function () {
    $admin = AdminUser::factory()->create();
    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id]);
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'push_enabled' => false,
        'quiet_hours_start' => '00:00',
        'quiet_hours_end' => '23:59',
        'quiet_hours_timezone' => 'UTC',
    ]);

    $results = app(NotifyCustomerOfCreditGrantAction::class)->handle($admin, $user, $team, ['push'], 'AI question', 20);

    // Preference-gated, it would return 'muted_by_preference'/'quiet_hours' —
    // reaching 'no_devices' proves the bypass skipped both checks.
    expect($results['push'])->toBe('no_devices');
});

test('email notification does not count toward the team\'s email_monthly quota', function () {
    Mail::fake();

    $admin = AdminUser::factory()->create();
    $user = User::factory()->create(['marketing_opt_in' => false]);
    $team = Team::factory()->create(['owner_id' => $user->id]);

    app(NotifyCustomerOfCreditGrantAction::class)->handle($admin, $user, $team, ['email'], 'email', 50);

    Mail::assertQueued(AdminCreditGrantMail::class);
    expect(Notification::emailsSentThisMonth($team))->toBe(0);
    expect(Notification::query()->where('user_id', $user->id)->where('type', Notification::TYPE_ADMIN_NOTE)->exists())->toBeTrue();
});

test('the notify step is audit-logged with channels and credit details', function () {
    Mail::fake();

    $admin = AdminUser::factory()->create();
    $user = User::factory()->create(['marketing_opt_in' => false]);
    $team = Team::factory()->create(['owner_id' => $user->id]);

    app(NotifyCustomerOfCreditGrantAction::class)->handle($admin, $user, $team, ['email'], 'email', 50, 'Enjoy!');

    $log = AdminAuditLog::query()->where('action', 'customer.notify_credit_grant')->where('target_id', $team->id)->first();
    expect($log)->not->toBeNull();
    expect($log->after['channels'])->toBe(['email']);
    expect($log->after['credits'])->toBe(50);
    expect($log->after['note'])->toBe('Enjoy!');
});
