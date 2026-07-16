<?php

use App\Actions\Admin\GetOpsHealthSnapshotAction;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\AppConfig;
use App\Models\Notification;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\SmsLedger;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('the ops page requires admin authentication', function () {
    test()->get('/admin/ops')->assertRedirect('/admin/login');
});

test('an authenticated admin can view the ops page', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')->get('/admin/ops')->assertOk();
});

test('connection health counts needs_reauth, disconnected, and stale connections correctly', function () {
    $team = Team::factory()->create();

    StoreConnection::factory()->create(['team_id' => $team->id, 'status' => StoreConnection::STATUS_ACTIVE, 'last_sync_at' => now()]);
    StoreConnection::factory()->create(['team_id' => $team->id, 'status' => StoreConnection::STATUS_NEEDS_REAUTH]);
    StoreConnection::factory()->create(['team_id' => $team->id, 'status' => StoreConnection::STATUS_DISCONNECTED]);
    StoreConnection::factory()->create(['team_id' => $team->id, 'status' => StoreConnection::STATUS_ACTIVE, 'last_sync_at' => now()->subHours(3)]);
    StoreConnection::factory()->create(['team_id' => $team->id, 'status' => StoreConnection::STATUS_ACTIVE, 'last_sync_at' => null]);

    $health = app(GetOpsHealthSnapshotAction::class)->handle();

    expect($health['connections'])->toBe([
        'total' => 5,
        'needs_reauth' => 1,
        'disconnected' => 1,
        'stale' => 1,
        'never_synced' => 1,
    ]);
});

test('failed jobs are surfaced from the real failed_jobs table', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => "RuntimeException: something broke\n#0 stack trace",
        'failed_at' => now(),
    ]);

    $health = app(GetOpsHealthSnapshotAction::class)->handle();

    expect($health['failed_jobs']['total'])->toBe(1);
    expect($health['failed_jobs']['recent'][0]['exception_summary'])->toContain('RuntimeException: something broke');
});

test('sms top consumers are ranked by credits consumed this month', function () {
    $bigSpender = Team::factory()->create(['name' => 'Big Spender']);
    $smallSpender = Team::factory()->create(['name' => 'Small Spender']);

    SmsLedger::factory()->create(['team_id' => $bigSpender->id, 'reason' => SmsLedger::REASON_SEND, 'delta' => -50, 'balance_after' => 50]);
    SmsLedger::factory()->create(['team_id' => $smallSpender->id, 'reason' => SmsLedger::REASON_SEND, 'delta' => -5, 'balance_after' => 95]);
    SmsLedger::factory()->create(['team_id' => $bigSpender->id, 'reason' => SmsLedger::REASON_MONTHLY_GRANT, 'delta' => 100, 'balance_after' => 150]);

    $health = app(GetOpsHealthSnapshotAction::class)->handle();

    expect($health['sms']['consumed_this_month'])->toBe(55);
    expect($health['sms']['top_consumers'][0]['team_name'])->toBe('Big Spender');
    expect($health['sms']['top_consumers'][0]['consumed'])->toBe(50);
});

test('a team executing rules above the threshold is flagged as a runaway abuse risk', function () {
    $runawayTeam = Team::factory()->create(['name' => 'Runaway Co']);
    $normalTeam = Team::factory()->create(['name' => 'Normal Co']);

    $runawayRule = Rule::factory()->create(['team_id' => $runawayTeam->id]);
    $normalRule = Rule::factory()->create(['team_id' => $normalTeam->id]);

    RuleExecution::factory()->count(51)->create(['rule_id' => $runawayRule->id, 'fired_at' => now()]);
    RuleExecution::factory()->count(5)->create(['rule_id' => $normalRule->id, 'fired_at' => now()]);

    $health = app(GetOpsHealthSnapshotAction::class)->handle();

    expect($health['abuse']['runaway_rule_teams'])->toHaveCount(1);
    expect($health['abuse']['runaway_rule_teams'][0]['team_name'])->toBe('Runaway Co');
    expect($health['abuse']['runaway_rule_teams'][0]['executions_last_hour'])->toBe(51);
});

test('notification volume groups by type over the last 24 hours only', function () {
    $user = User::factory()->create();

    Notification::factory()->create(['user_id' => $user->id, 'type' => Notification::TYPE_RULE_PUSH, 'created_at' => now()]);
    Notification::factory()->create(['user_id' => $user->id, 'type' => Notification::TYPE_RULE_PUSH, 'created_at' => now()]);
    Notification::factory()->create(['user_id' => $user->id, 'type' => Notification::TYPE_RULE_EMAIL, 'created_at' => now()->subDays(2)]);

    $health = app(GetOpsHealthSnapshotAction::class)->handle();

    expect($health['notification_volume_24h'])->toBe([Notification::TYPE_RULE_PUSH => 2]);
});

test('an admin can update app config and it is audit logged', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->put('/admin/ops/config', ['key' => AppConfig::KEY_MIN_VERSION, 'value' => '2.1.0'])
        ->assertRedirect();

    $config = AppConfig::query()->where('key', AppConfig::KEY_MIN_VERSION)->firstOrFail();
    expect($config->value)->toBe('2.1.0');
    expect($config->updated_by)->toBe($admin->id);
    expect(AdminAuditLog::query()->where('action', 'app_config.update')->where('admin_id', $admin->id)->exists())->toBeTrue();
});

test('a readonly admin cannot update app config', function () {
    $admin = AdminUser::factory()->readonly()->create();

    test()->actingAs($admin, 'admin')
        ->put('/admin/ops/config', ['key' => AppConfig::KEY_MAINTENANCE_MODE, 'value' => true])
        ->assertForbidden();
});
