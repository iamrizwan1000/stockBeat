<?php

use App\Actions\Inbox\SendInboxMessageAction;
use App\Mail\InboxMessageMail;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.inbound_email.domain' => 'mail.stockbeat.app']);
    Mail::fake();
});

test('sending a message with a known customer email queues it and marks it sent', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $thread = InboxThread::factory()->create(['team_id' => $team->id, 'customer_email' => 'buyer@example.com']);

    $message = app(SendInboxMessageAction::class)->handle($owner, $thread, 'Your order shipped!');

    expect($message->direction)->toBe(InboxMessage::DIRECTION_OUT);
    expect($message->status)->toBe(InboxMessage::STATUS_SENT);
    expect($message->sent_by)->toBe($owner->id);
    expect($thread->fresh()->last_message_at)->not->toBeNull();
    Mail::assertQueued(InboxMessageMail::class);
});

test('sending a message with no customer email on the thread fails without sending mail', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $thread = InboxThread::factory()->create(['team_id' => $team->id, 'customer_email' => null]);

    $message = app(SendInboxMessageAction::class)->handle($owner, $thread, 'Hello');

    expect($message->status)->toBe(InboxMessage::STATUS_FAILED);
    Mail::assertNothingQueued();
});
