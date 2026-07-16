<?php

use App\Events\SupportMessageSent;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('getting the thread creates one on first visit and reuses it after', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $first = test()->getJson('/api/v1/support/thread')->assertOk()->json('data.thread.id');
    $second = test()->getJson('/api/v1/support/thread')->assertOk()->json('data.thread.id');

    expect($first)->toBe($second);
    expect(SupportThread::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('sending a message creates it, updates the thread, and broadcasts', function () {
    Event::fake([SupportMessageSent::class]);
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/support/messages', ['body' => 'My orders stopped syncing'])
        ->assertCreated()
        ->assertJsonPath('data.message.body', 'My orders stopped syncing')
        ->assertJsonPath('data.message.direction', SupportMessage::DIRECTION_USER);

    $thread = SupportThread::query()->where('user_id', $user->id)->firstOrFail();
    expect($thread->status)->toBe(SupportThread::STATUS_OPEN);
    expect($thread->last_message_at)->not->toBeNull();
    Event::assertDispatched(SupportMessageSent::class);
});

test('sending a message reopens a resolved thread', function () {
    $user = User::factory()->create();
    $thread = SupportThread::factory()->create(['user_id' => $user->id, 'status' => SupportThread::STATUS_RESOLVED]);
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/support/messages', ['body' => 'Still an issue'])->assertCreated();

    expect($thread->fresh()->status)->toBe(SupportThread::STATUS_OPEN);
});

test('internal notes are never returned to the user', function () {
    $user = User::factory()->create();
    $thread = SupportThread::factory()->create(['user_id' => $user->id]);
    SupportMessage::factory()->create(['thread_id' => $thread->id, 'direction' => SupportMessage::DIRECTION_USER, 'body' => 'visible']);
    SupportMessage::factory()->create(['thread_id' => $thread->id, 'direction' => SupportMessage::DIRECTION_NOTE, 'body' => 'secret note']);
    Sanctum::actingAs($user);

    $response = test()->getJson('/api/v1/support/thread')->assertOk();

    $bodies = collect($response->json('data.messages'))->pluck('body');
    expect($bodies->all())->toBe(['visible']);
});

test('support endpoints require authentication', function () {
    test()->getJson('/api/v1/support/thread')->assertUnauthorized();
    test()->postJson('/api/v1/support/messages', ['body' => 'hi'])->assertUnauthorized();
});
