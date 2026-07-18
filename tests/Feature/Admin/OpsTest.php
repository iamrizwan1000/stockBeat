<?php

use App\Actions\Admin\GetOpsHealthSnapshotAction;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\AppConfig;
use App\Models\Notification;
use App\Models\OpsHealthSnapshot;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\SmsLedger;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use App\Support\Connections\ApiQuotaTracker;
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

test('a team over the SMS cost threshold this month is surfaced in the abuse block', function () {
    $bigSpender = Team::factory()->create(['name' => 'Big Spender']);
    $normalSpender = Team::factory()->create(['name' => 'Normal Spender']);

    SmsLedger::factory()->create(['team_id' => $bigSpender->id, 'reason' => SmsLedger::REASON_SEND, 'delta' => -250, 'balance_after' => 0]);
    SmsLedger::factory()->create(['team_id' => $normalSpender->id, 'reason' => SmsLedger::REASON_SEND, 'delta' => -5, 'balance_after' => 95]);

    $health = app(GetOpsHealthSnapshotAction::class)->handle();

    expect($health['abuse']['high_sms_cost_teams'])->toHaveCount(1);
    expect($health['abuse']['high_sms_cost_teams'][0]['team_name'])->toBe('Big Spender');
    expect($health['abuse']['high_sms_cost_teams'][0]['consumed'])->toBe(250);
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

test('the ops page renders api quota usage, sms anomalies, and trending sections', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->get('/admin/ops')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('health.api_quota_usage.etsy')
            ->has('health.api_quota_usage.ebay')
            ->has('health.api_quota_usage.amazon')
            ->has('health.api_quota_usage.tiktok')
            ->has('health.sms_anomalies')
            ->has('health.trending')
        );
});

test('api quota usage reports calls tracked today against each platform daily limit', function () {
    ApiQuotaTracker::recordCall(StoreConnection::PLATFORM_ETSY, 100);
    ApiQuotaTracker::recordCall(StoreConnection::PLATFORM_EBAY, 50);
    ApiQuotaTracker::recordCall(StoreConnection::PLATFORM_AMAZON, 10);
    ApiQuotaTracker::recordCall(StoreConnection::PLATFORM_TIKTOK, 5);

    $health = app(GetOpsHealthSnapshotAction::class)->handle();

    expect($health['api_quota_usage']['etsy']['calls_today'])->toBe(100);
    expect($health['api_quota_usage']['etsy']['daily_limit'])->toBe(10_000);
    expect($health['api_quota_usage']['etsy']['pct_used'])->toBe(1.0);

    expect($health['api_quota_usage']['ebay']['calls_today'])->toBe(50);
    expect($health['api_quota_usage']['amazon']['calls_today'])->toBe(10);

    expect($health['api_quota_usage']['tiktok']['calls_today'])->toBe(5);
    expect($health['api_quota_usage']['tiktok']['daily_limit'])->toBeNull();
    expect($health['api_quota_usage']['tiktok']['pct_used'])->toBeNull();
});

test('a team whose sms volume spikes far above its own baseline is flagged as an anomaly', function () {
    $spikingTeam = Team::factory()->create(['name' => 'Spiking Co']);
    $stableTeam = Team::factory()->create(['name' => 'Stable Co']);

    // Both teams have the same steady history: ~5 credits/day for the
    // trailing 28 days before the current 24h window.
    foreach ([$spikingTeam, $stableTeam] as $team) {
        for ($daysAgo = 2; $daysAgo <= 29; $daysAgo++) {
            SmsLedger::factory()->create([
                'team_id' => $team->id,
                'reason' => SmsLedger::REASON_SEND,
                'delta' => -5,
                'balance_after' => 0,
                'created_at' => now()->subDays($daysAgo),
            ]);
        }
    }

    // Spiking Co sends a huge burst in the last 24h (20x its ~5/day
    // baseline); Stable Co keeps sending its normal ~5/day.
    SmsLedger::factory()->create([
        'team_id' => $spikingTeam->id,
        'reason' => SmsLedger::REASON_SEND,
        'delta' => -100,
        'balance_after' => 0,
        'created_at' => now()->subHours(2),
    ]);
    SmsLedger::factory()->create([
        'team_id' => $stableTeam->id,
        'reason' => SmsLedger::REASON_SEND,
        'delta' => -5,
        'balance_after' => 0,
        'created_at' => now()->subHours(2),
    ]);

    $health = app(GetOpsHealthSnapshotAction::class)->handle();

    $flaggedTeamIds = array_column($health['sms_anomalies'], 'team_id');

    expect($flaggedTeamIds)->toContain($spikingTeam->id);
    expect($flaggedTeamIds)->not->toContain($stableTeam->id);

    $flagged = collect($health['sms_anomalies'])->firstWhere('team_id', $spikingTeam->id);
    expect($flagged['current'])->toBe(100);
    expect($flagged['multiple'])->toBeGreaterThan(5.0);
});

test('a team with no sms baseline history is never flagged even with a large first send', function () {
    $newTeam = Team::factory()->create(['name' => 'Brand New Co']);

    SmsLedger::factory()->create([
        'team_id' => $newTeam->id,
        'reason' => SmsLedger::REASON_SEND,
        'delta' => -100,
        'balance_after' => 0,
        'created_at' => now()->subHours(1),
    ]);

    $health = app(GetOpsHealthSnapshotAction::class)->handle();

    expect(array_column($health['sms_anomalies'], 'team_id'))->not->toContain($newTeam->id);
});

test('trending returns the last 30 days of recorded ops health snapshots in order', function () {
    OpsHealthSnapshot::factory()->create(['date' => now()->subDays(40)->toDateString(), 'active_teams' => 999]);
    OpsHealthSnapshot::factory()->create(['date' => now()->subDays(2)->toDateString(), 'active_teams' => 3]);
    OpsHealthSnapshot::factory()->create(['date' => now()->subDay()->toDateString(), 'active_teams' => 5]);

    $health = app(GetOpsHealthSnapshotAction::class)->handle();

    expect($health['trending'])->toHaveCount(2);
    expect($health['trending'][0]['active_teams'])->toBe(3);
    expect($health['trending'][1]['active_teams'])->toBe(5);
});
