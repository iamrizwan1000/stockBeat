<?php

use App\Actions\Inbox\ReceiveInboxReplyAction;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Notification;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a reply from the threads own customer email is recorded and notifies the assignee', function () {
    $assignee = User::factory()->create();
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $thread = InboxThread::factory()->create([
        'team_id' => $team->id,
        'customer_email' => 'buyer@example.com',
        'assigned_to' => $assignee->id,
    ]);

    $message = app(ReceiveInboxReplyAction::class)->handle($thread, 'buyer@example.com', 'Where is my order?');

    expect($message)->not->toBeNull();
    expect($message->direction)->toBe(InboxMessage::DIRECTION_IN);
    expect($message->status)->toBe(InboxMessage::STATUS_DELIVERED);
    expect($thread->fresh()->last_message_at)->not->toBeNull();
    expect(Notification::query()->where('user_id', $assignee->id)->where('type', Notification::TYPE_INBOX_MESSAGE)->exists())->toBeTrue();
});

test('a reply falls back to notifying the team owner when the thread is unassigned', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $thread = InboxThread::factory()->create([
        'team_id' => $team->id,
        'customer_email' => 'buyer@example.com',
        'assigned_to' => null,
    ]);

    app(ReceiveInboxReplyAction::class)->handle($thread, 'buyer@example.com', 'Any update?');

    expect(Notification::query()->where('user_id', $owner->id)->where('type', Notification::TYPE_INBOX_MESSAGE)->exists())->toBeTrue();
});

test('a reply from a mismatched email address is silently dropped', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $thread = InboxThread::factory()->create(['team_id' => $team->id, 'customer_email' => 'buyer@example.com']);

    $message = app(ReceiveInboxReplyAction::class)->handle($thread, 'attacker@example.com', 'Injected message');

    expect($message)->toBeNull();
    expect(InboxMessage::query()->where('thread_id', $thread->id)->count())->toBe(0);
});

test('a reply on a thread with no customer email on file is dropped', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $thread = InboxThread::factory()->create(['team_id' => $team->id, 'customer_email' => null]);

    $message = app(ReceiveInboxReplyAction::class)->handle($thread, 'buyer@example.com', 'Hello');

    expect($message)->toBeNull();
});
