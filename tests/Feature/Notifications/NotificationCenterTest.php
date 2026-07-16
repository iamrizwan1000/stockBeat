<?php

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('notification endpoints require authentication', function () {
    test()->getJson('/api/v1/notifications')->assertUnauthorized();
    test()->postJson('/api/v1/notifications/read')->assertUnauthorized();
});

test('a user only sees their own notifications', function () {
    $user = User::factory()->create();
    Notification::factory()->create(['user_id' => $user->id]);

    $otherUser = User::factory()->create();
    Notification::factory()->create(['user_id' => $otherUser->id]);

    Sanctum::actingAs($user);
    test()->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data.notifications');
});

test('marking all as read clears every unread notification', function () {
    $user = User::factory()->create();
    Notification::factory()->count(3)->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $response = test()->postJson('/api/v1/notifications/read');

    $response->assertOk()->assertJsonPath('data.marked_read', 3);
    expect(Notification::query()->where('user_id', $user->id)->whereNull('read_at')->count())->toBe(0);
});

test('marking specific ids as read leaves the rest unread', function () {
    $user = User::factory()->create();
    $notifications = Notification::factory()->count(3)->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/notifications/read', ['ids' => [$notifications->first()->id]])
        ->assertOk()
        ->assertJsonPath('data.marked_read', 1);

    expect(Notification::query()->where('user_id', $user->id)->whereNull('read_at')->count())->toBe(2);
});
