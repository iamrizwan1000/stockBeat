<?php

use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('listing announcements requires authentication', function () {
    test()->getJson('/api/v1/announcements')->assertUnauthorized();
});

test('an active announcement is returned', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Announcement::factory()->create(['title' => 'New feature']);

    test()->getJson('/api/v1/announcements')
        ->assertOk()
        ->assertJsonCount(1, 'data.announcements')
        ->assertJsonPath('data.announcements.0.title', 'New feature');
});

test('a dismissed announcement stops appearing for that user only', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $announcement = Announcement::factory()->create();

    Sanctum::actingAs($user);
    test()->postJson("/api/v1/announcements/{$announcement->id}/dismiss")
        ->assertOk()
        ->assertJsonPath('message', 'Announcement dismissed.');

    test()->getJson('/api/v1/announcements')->assertOk()->assertJsonCount(0, 'data.announcements');

    Sanctum::actingAs($otherUser);
    test()->getJson('/api/v1/announcements')->assertOk()->assertJsonCount(1, 'data.announcements');
});

test('dismissing twice is idempotent, not an error', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $announcement = Announcement::factory()->create();

    test()->postJson("/api/v1/announcements/{$announcement->id}/dismiss")->assertOk();
    test()->postJson("/api/v1/announcements/{$announcement->id}/dismiss")->assertOk();

    expect(AnnouncementDismissal::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('a non-dismissible announcement cannot be dismissed', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $announcement = Announcement::factory()->create(['dismissible' => false]);

    test()->postJson("/api/v1/announcements/{$announcement->id}/dismiss")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('announcement');

    test()->getJson('/api/v1/announcements')->assertOk()->assertJsonCount(1, 'data.announcements');
});

test('dismissing a non-existent announcement 404s', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/announcements/999/dismiss')->assertNotFound();
});
