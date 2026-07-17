<?php

use App\Models\SupportThread;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('idle non-resolved threads are auto-closed after 7 days', function () {
    $idle = SupportThread::factory()->create([
        'status' => SupportThread::STATUS_AWAITING_USER,
        'last_message_at' => now()->subDays(8),
    ]);
    $recent = SupportThread::factory()->create([
        'status' => SupportThread::STATUS_OPEN,
        'last_message_at' => now()->subDays(2),
    ]);
    $neverMessaged = SupportThread::factory()->create([
        'status' => SupportThread::STATUS_OPEN,
        'last_message_at' => null,
        'created_at' => now()->subDays(10),
    ]);
    $alreadyResolved = SupportThread::factory()->create([
        'status' => SupportThread::STATUS_RESOLVED,
        'last_message_at' => now()->subDays(30),
    ]);

    test()->artisan('support:auto-close-idle-threads')->assertSuccessful();

    expect($idle->fresh()->status)->toBe(SupportThread::STATUS_RESOLVED);
    expect($neverMessaged->fresh()->status)->toBe(SupportThread::STATUS_RESOLVED);
    expect($recent->fresh()->status)->toBe(SupportThread::STATUS_OPEN);
    expect($alreadyResolved->fresh()->status)->toBe(SupportThread::STATUS_RESOLVED);
});
