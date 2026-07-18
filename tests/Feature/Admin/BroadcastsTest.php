<?php

use App\Mail\BroadcastMail;
use App\Mail\BroadcastTestMail;
use App\Models\AdminUser;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('a non-superadmin can schedule an all-audience broadcast, pending approval', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/broadcasts', [
            'audience_type' => Broadcast::AUDIENCE_ALL,
            'channels' => [Broadcast::CHANNEL_EMAIL],
            'title' => 'Hello everyone',
            'body' => 'Body',
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ])
        ->assertRedirect();

    $broadcast = Broadcast::query()->firstOrFail();
    expect($broadcast->status)->toBe(Broadcast::STATUS_SCHEDULED);
    expect($broadcast->approved_by)->toBeNull();
});

test('a non-superadmin can create an immediate all-audience draft but cannot send it', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/broadcasts', [
            'audience_type' => Broadcast::AUDIENCE_ALL,
            'channels' => [Broadcast::CHANNEL_EMAIL],
            'title' => 'Hello everyone',
            'body' => 'Body',
        ])
        ->assertRedirect();

    $broadcast = Broadcast::query()->firstOrFail();
    expect($broadcast->status)->toBe(Broadcast::STATUS_DRAFT);

    test()->actingAs($admin, 'admin')
        ->post("/admin/broadcasts/{$broadcast->id}/send")
        ->assertSessionHasErrors('broadcast');

    expect($broadcast->fresh()->status)->toBe(Broadcast::STATUS_DRAFT);
});

test('an unapproved all-audience broadcast cannot be sent, even by a superadmin', function () {
    Mail::fake();
    $superadmin = AdminUser::factory()->superadmin()->create();
    User::factory()->create(['marketing_opt_in' => true]);

    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_ALL,
        'channels' => [Broadcast::CHANNEL_EMAIL],
        'created_by' => $superadmin->id,
    ]);

    test()->actingAs($superadmin, 'admin')
        ->post("/admin/broadcasts/{$broadcast->id}/send")
        ->assertSessionHasErrors('broadcast');

    expect($broadcast->fresh()->status)->toBe(Broadcast::STATUS_DRAFT);
    Mail::assertNothingQueued();
});

test('a non-superadmin cannot approve an all-audience broadcast', function () {
    $admin = AdminUser::factory()->create();

    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_ALL,
        'channels' => [Broadcast::CHANNEL_EMAIL],
        'created_by' => $admin->id,
    ]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/broadcasts/{$broadcast->id}/approve")
        ->assertSessionHasErrors('broadcast');

    expect($broadcast->fresh()->approved_by)->toBeNull();
});

test('a superadmin can approve then send an all-audience broadcast', function () {
    Mail::fake();
    $superadmin = AdminUser::factory()->superadmin()->create();
    $recipient = User::factory()->create(['marketing_opt_in' => true]);

    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_ALL,
        'channels' => [Broadcast::CHANNEL_EMAIL],
        'created_by' => $superadmin->id,
    ]);

    test()->actingAs($superadmin, 'admin')
        ->post("/admin/broadcasts/{$broadcast->id}/approve")
        ->assertRedirect();

    $broadcast->refresh();
    expect($broadcast->approved_by)->toBe($superadmin->id);
    expect($broadcast->approved_at)->not->toBeNull();
    expect($broadcast->status)->toBe(Broadcast::STATUS_DRAFT);

    test()->actingAs($superadmin, 'admin')
        ->post("/admin/broadcasts/{$broadcast->id}/send")
        ->assertRedirect();

    $broadcast->refresh();
    expect($broadcast->status)->toBe(Broadcast::STATUS_SENT);
    expect($broadcast->sent_at)->not->toBeNull();

    expect(BroadcastDelivery::query()->where('broadcast_id', $broadcast->id)->where('user_id', $recipient->id)->where('status', BroadcastDelivery::STATUS_SENT)->exists())->toBeTrue();
    Mail::assertQueued(BroadcastMail::class);
});

test('a segmented send bypasses the approval gate entirely, even for a non-superadmin', function () {
    Mail::fake();
    $admin = AdminUser::factory()->create();
    $recipient = User::factory()->create(['marketing_opt_in' => true]);

    $segment = Segment::factory()->create(['filters' => []]);

    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_SEGMENT,
        'segment_id' => $segment->id,
        'channels' => [Broadcast::CHANNEL_EMAIL],
        'created_by' => $admin->id,
    ]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/broadcasts/{$broadcast->id}/send")
        ->assertRedirect();

    $broadcast->refresh();
    expect($broadcast->status)->toBe(Broadcast::STATUS_SENT);
    expect($broadcast->approved_by)->toBeNull();
    expect(BroadcastDelivery::query()->where('broadcast_id', $broadcast->id)->where('user_id', $recipient->id)->exists())->toBeTrue();
});

test('a broadcast to a segment only reaches matching users', function () {
    $admin = AdminUser::factory()->create();

    $optedOut = User::factory()->create(['marketing_opt_in' => false]);
    $optedIn = User::factory()->create(['marketing_opt_in' => true]);

    $segment = Segment::factory()->create(['filters' => ['marketing_opt_in' => false]]);

    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_SEGMENT,
        'segment_id' => $segment->id,
        'channels' => [Broadcast::CHANNEL_BANNER],
        'created_by' => $admin->id,
    ]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/broadcasts/{$broadcast->id}/send")
        ->assertRedirect();

    expect(BroadcastDelivery::query()->where('broadcast_id', $broadcast->id)->where('user_id', $optedOut->id)->exists())->toBeTrue();
    expect(BroadcastDelivery::query()->where('broadcast_id', $broadcast->id)->where('user_id', $optedIn->id)->exists())->toBeFalse();
});

test('email channel skips a user who has not opted into marketing', function () {
    Mail::fake();
    $admin = AdminUser::factory()->create();
    $optedOut = User::factory()->create(['marketing_opt_in' => false]);

    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_USER,
        'user_id' => $optedOut->id,
        'channels' => [Broadcast::CHANNEL_EMAIL],
        'created_by' => $admin->id,
    ]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/broadcasts/{$broadcast->id}/send")
        ->assertRedirect();

    $delivery = BroadcastDelivery::query()->where('broadcast_id', $broadcast->id)->where('user_id', $optedOut->id)->firstOrFail();
    expect($delivery->status)->toBe(BroadcastDelivery::STATUS_SKIPPED_NO_CONSENT);
    Mail::assertNothingQueued();
});

test('email channel skips a user who has muted email notifications, and future sends keep skipping them', function () {
    Mail::fake();
    $admin = AdminUser::factory()->create();
    $unsubscribed = User::factory()->create(['marketing_opt_in' => true]);
    NotificationPreference::factory()->create([
        'user_id' => $unsubscribed->id,
        'email_enabled' => false,
    ]);

    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_USER,
        'user_id' => $unsubscribed->id,
        'channels' => [Broadcast::CHANNEL_EMAIL],
        'created_by' => $admin->id,
    ]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/broadcasts/{$broadcast->id}/send")
        ->assertRedirect();

    $delivery = BroadcastDelivery::query()->where('broadcast_id', $broadcast->id)->where('user_id', $unsubscribed->id)->firstOrFail();
    expect($delivery->status)->toBe(BroadcastDelivery::STATUS_SKIPPED_UNSUBSCRIBED);
    Mail::assertNothingQueued();
});

test('banner and push deliveries are linked to their in-app notification for open tracking', function () {
    $admin = AdminUser::factory()->create();
    $recipient = User::factory()->create();

    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_USER,
        'user_id' => $recipient->id,
        'channels' => [Broadcast::CHANNEL_BANNER],
        'created_by' => $admin->id,
    ]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/broadcasts/{$broadcast->id}/send")
        ->assertRedirect();

    $delivery = BroadcastDelivery::query()->where('broadcast_id', $broadcast->id)->where('user_id', $recipient->id)->firstOrFail();
    expect($delivery->notification_id)->not->toBeNull();
    expect($delivery->opened_at)->toBeNull();

    Sanctum::actingAs($recipient);
    test()->postJson('/api/v1/notifications/read', ['ids' => [$delivery->notification_id]])
        ->assertOk();

    expect($delivery->fresh()->opened_at)->not->toBeNull();
});

test('banner channel always logs an in-app notification regardless of marketing consent', function () {
    $admin = AdminUser::factory()->create();
    $optedOut = User::factory()->create(['marketing_opt_in' => false]);

    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_USER,
        'user_id' => $optedOut->id,
        'channels' => [Broadcast::CHANNEL_BANNER],
        'title' => 'Hi {first_name}',
        'body' => 'Welcome',
        'created_by' => $admin->id,
    ]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/broadcasts/{$broadcast->id}/send")
        ->assertRedirect();

    $notification = Notification::query()->where('user_id', $optedOut->id)->where('type', Notification::TYPE_ADMIN_BROADCAST)->firstOrFail();
    expect($notification->title)->toBe('Hi '.explode(' ', $optedOut->name)[0]);
});

test('sending twice is rejected', function () {
    Mail::fake();
    $admin = AdminUser::factory()->create();
    $recipient = User::factory()->create();

    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_USER,
        'user_id' => $recipient->id,
        'channels' => [Broadcast::CHANNEL_BANNER],
        'created_by' => $admin->id,
    ]);

    test()->actingAs($admin, 'admin')->post("/admin/broadcasts/{$broadcast->id}/send")->assertRedirect();
    test()->actingAs($admin, 'admin')->post("/admin/broadcasts/{$broadcast->id}/send")->assertSessionHasErrors('broadcast');
});

test('send test delivers to the admins own email with placeholder variables', function () {
    Mail::fake();
    $admin = AdminUser::factory()->create(['email' => 'admin@example.com']);

    $broadcast = Broadcast::factory()->create([
        'title' => 'Hi {first_name}',
        'body' => 'Your plan: {plan}',
        'created_by' => $admin->id,
    ]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/broadcasts/{$broadcast->id}/send-test")
        ->assertRedirect();

    Mail::assertQueued(BroadcastTestMail::class, fn ($mail) => $mail->hasTo('admin@example.com') && $mail->title === 'Hi Jamie');
});
