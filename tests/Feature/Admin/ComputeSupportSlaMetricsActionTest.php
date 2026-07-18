<?php

use App\Actions\Admin\Support\ComputeSupportSlaMetricsAction;
use App\Models\AdminUser;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('first-response time is computed from the first user message to the first staff reply', function () {
    $opened = Carbon::parse('2026-07-01 09:00:00');
    $thread = SupportThread::factory()->create(['created_at' => $opened]);

    SupportMessage::factory()->create([
        'thread_id' => $thread->id,
        'direction' => SupportMessage::DIRECTION_USER,
        'created_at' => $opened,
    ]);
    SupportMessage::factory()->create([
        'thread_id' => $thread->id,
        'direction' => SupportMessage::DIRECTION_STAFF,
        'created_at' => $opened->copy()->addMinutes(45),
    ]);

    $metrics = app(ComputeSupportSlaMetricsAction::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-08-01'),
    );

    expect($metrics['first_response']['avg_minutes'])->toBe(45.0);
    expect($metrics['first_response']['median_minutes'])->toBe(45.0);
    expect($metrics['first_response']['sample_size'])->toBe(1);
});

test('a thread with no staff reply yet is excluded from first-response stats rather than counted as zero', function () {
    $opened = Carbon::parse('2026-07-01 09:00:00');
    $thread = SupportThread::factory()->create(['created_at' => $opened]);

    SupportMessage::factory()->create([
        'thread_id' => $thread->id,
        'direction' => SupportMessage::DIRECTION_USER,
        'created_at' => $opened,
    ]);

    $metrics = app(ComputeSupportSlaMetricsAction::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-08-01'),
    );

    expect($metrics['first_response']['sample_size'])->toBe(0);
    expect($metrics['first_response']['avg_minutes'])->toBeNull();
});

test('a thread with no messages at all falls back to thread creation as the first-response start point', function () {
    $opened = Carbon::parse('2026-07-01 09:00:00');
    $thread = SupportThread::factory()->create(['created_at' => $opened]);

    SupportMessage::factory()->create([
        'thread_id' => $thread->id,
        'direction' => SupportMessage::DIRECTION_STAFF,
        'created_at' => $opened->copy()->addMinutes(10),
    ]);

    $metrics = app(ComputeSupportSlaMetricsAction::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-08-01'),
    );

    expect($metrics['first_response']['sample_size'])->toBe(1);
    expect($metrics['first_response']['avg_minutes'])->toBe(10.0);
});

test('resolution time is computed from thread creation to resolved_at, in minutes', function () {
    $opened = Carbon::parse('2026-07-01 09:00:00');

    SupportThread::factory()->create([
        'created_at' => $opened,
        'resolved_at' => $opened->copy()->addHours(2),
    ]);
    SupportThread::factory()->create([
        'created_at' => $opened,
        'resolved_at' => $opened->copy()->addHours(4),
    ]);

    $metrics = app(ComputeSupportSlaMetricsAction::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-08-01'),
    );

    expect($metrics['resolution']['sample_size'])->toBe(2);
    expect($metrics['resolution']['avg_minutes'])->toBe(180.0);
    expect($metrics['resolution']['median_minutes'])->toBe(180.0);
});

test('unresolved threads and threads with no resolved_at are excluded from resolution stats', function () {
    SupportThread::factory()->create(['resolved_at' => null]);

    $metrics = app(ComputeSupportSlaMetricsAction::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-08-01'),
    );

    expect($metrics['resolution']['sample_size'])->toBe(0);
    expect($metrics['resolution']['avg_minutes'])->toBeNull();
});

test('per-agent stats report each agent\'s total assignments and resolved-in-period average resolution time', function () {
    $agentA = AdminUser::factory()->create(['name' => 'Agent A']);
    $agentB = AdminUser::factory()->create(['name' => 'Agent B']);

    $opened = Carbon::parse('2026-07-01 09:00:00');

    // Agent A: 2 threads assigned, 1 resolved in period (2 hours).
    SupportThread::factory()->create([
        'assigned_admin_id' => $agentA->id,
        'created_at' => $opened,
        'resolved_at' => $opened->copy()->addHours(2),
    ]);
    SupportThread::factory()->create([
        'assigned_admin_id' => $agentA->id,
        'status' => SupportThread::STATUS_OPEN,
        'resolved_at' => null,
    ]);

    // Agent B: 1 thread assigned, resolved in period (1 hour).
    SupportThread::factory()->create([
        'assigned_admin_id' => $agentB->id,
        'created_at' => $opened,
        'resolved_at' => $opened->copy()->addHour(),
    ]);

    $metrics = app(ComputeSupportSlaMetricsAction::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-08-01'),
    );

    $agents = collect($metrics['agents'])->keyBy('admin_id');

    expect($agents[$agentA->id]['assigned_total'])->toBe(2);
    expect($agents[$agentA->id]['resolved_in_period'])->toBe(1);
    expect($agents[$agentA->id]['avg_resolution_minutes'])->toBe(120.0);

    expect($agents[$agentB->id]['assigned_total'])->toBe(1);
    expect($agents[$agentB->id]['resolved_in_period'])->toBe(1);
    expect($agents[$agentB->id]['avg_resolution_minutes'])->toBe(60.0);
});

test('unassigned threads do not produce a phantom agent row', function () {
    SupportThread::factory()->create(['assigned_admin_id' => null]);

    $metrics = app(ComputeSupportSlaMetricsAction::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-08-01'),
    );

    expect($metrics['agents'])->toBe([]);
});

test('CSAT rollup counts thumbs-up and thumbs-down and computes a positive percentage', function () {
    $opened = Carbon::parse('2026-07-01 09:00:00');

    SupportThread::factory()->create(['created_at' => $opened, 'csat' => 1]);
    SupportThread::factory()->create(['created_at' => $opened, 'csat' => 1]);
    SupportThread::factory()->create(['created_at' => $opened, 'csat' => 1]);
    SupportThread::factory()->create(['created_at' => $opened, 'csat' => 0]);
    SupportThread::factory()->create(['created_at' => $opened, 'csat' => null]);

    $metrics = app(ComputeSupportSlaMetricsAction::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-08-01'),
    );

    expect($metrics['csat'])->toBe([
        'positive' => 3,
        'negative' => 1,
        'total' => 4,
        'positive_pct' => 75.0,
    ]);
});

test('CSAT rollup reports a null percentage rather than dividing by zero when nothing is rated', function () {
    SupportThread::factory()->create(['csat' => null]);

    $metrics = app(ComputeSupportSlaMetricsAction::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-08-01'),
    );

    expect($metrics['csat']['total'])->toBe(0);
    expect($metrics['csat']['positive_pct'])->toBeNull();
});

test('threads created outside the requested period are excluded from first-response and CSAT stats', function () {
    SupportThread::factory()->create(['created_at' => Carbon::parse('2026-01-01'), 'csat' => 1]);

    $metrics = app(ComputeSupportSlaMetricsAction::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-08-01'),
    );

    expect($metrics['first_response']['sample_size'])->toBe(0);
    expect($metrics['csat']['total'])->toBe(0);
});

test('threads resolved outside the requested period are excluded from resolution stats even if opened inside it', function () {
    $opened = Carbon::parse('2026-07-01 09:00:00');

    SupportThread::factory()->create([
        'created_at' => $opened,
        'resolved_at' => Carbon::parse('2026-09-01'),
    ]);

    $metrics = app(ComputeSupportSlaMetricsAction::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-08-01'),
    );

    expect($metrics['resolution']['sample_size'])->toBe(0);
});

test('with no from/to arguments the period defaults to the last 30 days', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));

    $metrics = app(ComputeSupportSlaMetricsAction::class)->handle();

    expect(Carbon::parse($metrics['period']['from'])->diffInDays(Carbon::parse($metrics['period']['to'])))
        ->toBe(30.0);

    Carbon::setTestNow();
});
