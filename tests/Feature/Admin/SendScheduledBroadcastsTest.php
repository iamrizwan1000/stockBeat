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

test('an all-audience broadcast scheduled by a superadmin sends using them as the approver', function () {
    Mail::fake();
    $superadmin = AdminUser::factory()->superadmin()->create();
    User::factory()->create();

    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_ALL,
        'channels' => [Broadcast::CHANNEL_BANNER],
        'status' => Broadcast::STATUS_SCHEDULED,
        'scheduled_at' => now()->subMinute(),
        'created_by' => $superadmin->id,
    ]);

    Artisan::call('messaging:send-scheduled-broadcasts');

    $broadcast->refresh();
    expect($broadcast->status)->toBe(Broadcast::STATUS_SENT);
    expect($broadcast->approved_by)->toBe($superadmin->id);
});
