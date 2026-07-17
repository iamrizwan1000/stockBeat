<?php

use App\Models\SupportThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('a user can rate a resolved thread', function () {
    $user = User::factory()->create();
    $thread = SupportThread::factory()->create(['user_id' => $user->id, 'status' => SupportThread::STATUS_RESOLVED]);
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/support/csat', ['rating' => 1])
        ->assertOk()
        ->assertJsonPath('data.thread.csat', 1);

    expect($thread->fresh()->csat)->toBe(1);
});

test('rating an unresolved thread is rejected', function () {
    $user = User::factory()->create();
    SupportThread::factory()->create(['user_id' => $user->id, 'status' => SupportThread::STATUS_OPEN]);
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/support/csat', ['rating' => 1])->assertUnprocessable();
});

test('a thread can only be rated once', function () {
    $user = User::factory()->create();
    SupportThread::factory()->create(['user_id' => $user->id, 'status' => SupportThread::STATUS_RESOLVED, 'csat' => 1]);
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/support/csat', ['rating' => 0])->assertUnprocessable();
});

test('rating with no support thread yet returns not found', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/support/csat', ['rating' => 1])->assertNotFound();
});

test('csat requires authentication', function () {
    test()->postJson('/api/v1/support/csat', ['rating' => 1])->assertUnauthorized();
});
