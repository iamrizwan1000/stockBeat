<?php

use App\Actions\Inbox\AssignInboxThreadAction;
use App\Models\InboxThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('assigning a thread to a user sets assigned_to', function () {
    $thread = InboxThread::factory()->create(['assigned_to' => null]);
    $assignee = User::factory()->create();

    $thread = app(AssignInboxThreadAction::class)->handle($thread, $assignee);

    expect($thread->assigned_to)->toBe($assignee->id);
});

test('passing null unassigns the thread', function () {
    $assignee = User::factory()->create();
    $thread = InboxThread::factory()->create(['assigned_to' => $assignee->id]);

    $thread = app(AssignInboxThreadAction::class)->handle($thread, null);

    expect($thread->assigned_to)->toBeNull();
});
