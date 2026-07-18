<?php

use App\Models\AdminUser;
use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('a due scheduled broadcast is sent and an unarrived one is left alone', function () {
    Mail::fake();
    $admin = AdminUser::factory()->create();
    $recipient = User::factory()->create();

    $due = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_USER,
        'user_id' => $recipient->id,
        'channels' => [Broadcast::CHANNEL_BANNER],
        'status' => Broadcast::STATUS_SCHEDULED,
        'scheduled_at' => now()->subMinute(),
        'created_by' => $admin->id,
    ]);

    $notYetDue = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_USER,
        'user_id' => $recipient->id,
        'channels' => [Broadcast::CHANNEL_BANNER],
        'status' => Broadcast::STATUS_SCHEDULED,
        'scheduled_at' => now()->addHour(),
        'created_by' => $admin->id,
    ]);

    Artisan::call('messaging:send-scheduled-broadcasts');

    expect($due->fresh()->status)->toBe(Broadcast::STATUS_SENT);
    expect($notYetDue->fresh()->status)->toBe(Broadcast::STATUS_SCHEDULED);
});

test('a scheduled all-audience broadcast already approved by a superadmin sends on schedule', function () {
    Mail::fake();
    $superadmin = AdminUser::factory()->superadmin()->create();
    User::factory()->create();

    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_ALL,
        'channels' => [Broadcast::CHANNEL_BANNER],
        'status' => Broadcast::STATUS_SCHEDULED,
        'scheduled_at' => now()->subMinute(),
        'created_by' => $superadmin->id,
        'approved_by' => $superadmin->id,
        'approved_at' => now(),
    ]);

    Artisan::call('messaging:send-scheduled-broadcasts');

    $broadcast->refresh();
    expect($broadcast->status)->toBe(Broadcast::STATUS_SENT);
    expect($broadcast->approved_by)->toBe($superadmin->id);
});

test('a scheduled all-audience broadcast without approval is left scheduled, not sent', function () {
    Mail::fake();
    $admin = AdminUser::factory()->create();
    User::factory()->create();

    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_ALL,
        'channels' => [Broadcast::CHANNEL_BANNER],
        'status' => Broadcast::STATUS_SCHEDULED,
        'scheduled_at' => now()->subMinute(),
        'created_by' => $admin->id,
    ]);

    Artisan::call('messaging:send-scheduled-broadcasts');

    expect($broadcast->fresh()->status)->toBe(Broadcast::STATUS_SCHEDULED);
});
