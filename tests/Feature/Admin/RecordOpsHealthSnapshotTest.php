<?php

use App\Actions\Admin\RecordOpsHealthSnapshotAction;
use App\Models\OpsHealthSnapshot;
use App\Models\Order;
use App\Models\SmsLedger;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('recording a snapshot writes a row with the expected scalar metrics', function () {
    $activeTeam = Team::factory()->create();
    $inactiveTeam = Team::factory()->create();

    $activeConnection = StoreConnection::factory()->create(['team_id' => $activeTeam->id, 'status' => StoreConnection::STATUS_ACTIVE]);
    StoreConnection::factory()->create(['team_id' => $inactiveTeam->id, 'status' => StoreConnection::STATUS_DISCONNECTED]);

    Order::factory()->count(3)->create(['team_id' => $activeTeam->id, 'connection_id' => $activeConnection->id]);

    Subscription::factory()->create([
        'team_id' => $activeTeam->id,
        'status' => Subscription::STATUS_ACTIVE,
        'product_id' => 'pro_monthly',
    ]);

    SmsLedger::factory()->create([
        'team_id' => $activeTeam->id,
        'reason' => SmsLedger::REASON_SEND,
        'delta' => -20,
        'balance_after' => 0,
    ]);

    $snapshot = app(RecordOpsHealthSnapshotAction::class)->handle();

    expect($snapshot->date->toDateString())->toBe(now()->toDateString());
    expect($snapshot->active_teams)->toBe(1);
    expect($snapshot->mrr)->toBe(17.99);
    expect($snapshot->total_orders_synced)->toBe(3);
    expect($snapshot->sms_cost_total)->toBe(20);
    expect(OpsHealthSnapshot::query()->count())->toBe(1);
});

test('running it twice for the same day updates the same row instead of duplicating', function () {
    $team = Team::factory()->create();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'status' => StoreConnection::STATUS_ACTIVE]);

    app(RecordOpsHealthSnapshotAction::class)->handle();

    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    app(RecordOpsHealthSnapshotAction::class)->handle();

    expect(OpsHealthSnapshot::query()->count())->toBe(1);
    expect(OpsHealthSnapshot::query()->first()->total_orders_synced)->toBe(1);
});

test('the ops:record-daily-snapshot command runs and writes a row', function () {
    test()->artisan('ops:record-daily-snapshot')->assertExitCode(0);

    expect(OpsHealthSnapshot::query()->whereDate('date', now()->toDateString())->exists())->toBeTrue();
});
