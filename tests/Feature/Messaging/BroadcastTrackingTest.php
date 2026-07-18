<?php

use App\Models\AdminUser;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

test('the open-tracking pixel records opened_at once and returns real gif bytes', function () {
    $delivery = BroadcastDelivery::factory()->create([
        'channel' => Broadcast::CHANNEL_EMAIL,
        'status' => BroadcastDelivery::STATUS_SENT,
    ]);

    $url = URL::signedRoute('broadcasts.track-open', ['delivery' => $delivery->id]);

    $response = test()->get($url);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('image/gif');
    expect(strlen($response->getContent()))->toBeGreaterThan(0);
    expect(substr($response->getContent(), 0, 3))->toBe('GIF');

    $firstOpenedAt = $delivery->fresh()->opened_at;
    expect($firstOpenedAt)->not->toBeNull();

    // Hitting it again (an email client re-fetching the image) must not
    // move the timestamp.
    test()->travel(1)->hour();
    test()->get($url)->assertOk();

    expect($delivery->fresh()->opened_at->equalTo($firstOpenedAt))->toBeTrue();
});

test('the tracking pixel route rejects an unsigned or tampered url', function () {
    $delivery = BroadcastDelivery::factory()->create();

    test()->get("/broadcasts/track/{$delivery->id}/open.gif")->assertForbidden();

    expect($delivery->fresh()->opened_at)->toBeNull();
});

test('the unsubscribe link flips the recipient email preference off and future sends skip them', function () {
    $user = User::factory()->create(['marketing_opt_in' => true]);
    $delivery = BroadcastDelivery::factory()->create([
        'user_id' => $user->id,
        'channel' => Broadcast::CHANNEL_EMAIL,
        'status' => BroadcastDelivery::STATUS_SENT,
    ]);

    $url = URL::signedRoute('broadcasts.unsubscribe', ['delivery' => $delivery->id]);

    test()->get($url)->assertOk();

    $preference = NotificationPreference::query()->where('user_id', $user->id)->firstOrFail();
    expect($preference->email_enabled)->toBeFalse();
    expect($delivery->fresh()->unsubscribed_at)->not->toBeNull();

    // A later broadcast to the same user now records a real skip, not a
    // silent omission.
    $admin = AdminUser::factory()->create();
    $broadcast = Broadcast::factory()->create([
        'audience_type' => Broadcast::AUDIENCE_USER,
        'user_id' => $user->id,
        'channels' => [Broadcast::CHANNEL_EMAIL],
        'created_by' => $admin->id,
    ]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/broadcasts/{$broadcast->id}/send")
        ->assertRedirect();

    $laterDelivery = BroadcastDelivery::query()
        ->where('broadcast_id', $broadcast->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($laterDelivery->status)->toBe(BroadcastDelivery::STATUS_SKIPPED_UNSUBSCRIBED);
});

test('the unsubscribe route rejects an unsigned or tampered url', function () {
    $user = User::factory()->create();
    $delivery = BroadcastDelivery::factory()->create(['user_id' => $user->id]);

    test()->get("/broadcasts/{$delivery->id}/unsubscribe")->assertForbidden();

    expect(NotificationPreference::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
