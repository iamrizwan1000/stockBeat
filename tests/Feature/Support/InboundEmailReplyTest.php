<?php

use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.inbound_email.webhook_secret' => 'test-secret']);
});

test('a reply from the threads own user is appended and reopens the thread', function () {
    $user = User::factory()->create(['email' => 'jamie@example.com']);
    $thread = SupportThread::factory()->create(['user_id' => $user->id, 'status' => SupportThread::STATUS_RESOLVED]);

    test()->withToken('test-secret')->postJson('/hooks/email-inbound', [
        'to' => "support+{$thread->id}@mail.stockbeat.app",
        'from' => 'jamie@example.com',
        'text' => 'Still having trouble, any update?',
    ])->assertOk();

    expect(SupportMessage::query()->where('thread_id', $thread->id)->where('body', 'Still having trouble, any update?')->exists())->toBeTrue();
    expect($thread->fresh()->status)->toBe(SupportThread::STATUS_OPEN);
});

test('a reply from a different email address is dropped', function () {
    $user = User::factory()->create(['email' => 'jamie@example.com']);
    $thread = SupportThread::factory()->create(['user_id' => $user->id]);

    test()->withToken('test-secret')->postJson('/hooks/email-inbound', [
        'to' => "support+{$thread->id}@mail.stockbeat.app",
        'from' => 'attacker@example.com',
        'text' => 'Injected message',
    ])->assertOk();

    expect(SupportMessage::query()->where('thread_id', $thread->id)->where('body', 'Injected message')->exists())->toBeFalse();
});

test('the wrong shared secret is rejected', function () {
    $user = User::factory()->create(['email' => 'jamie@example.com']);
    $thread = SupportThread::factory()->create(['user_id' => $user->id]);

    test()->withToken('wrong-secret')->postJson('/hooks/email-inbound', [
        'to' => "support+{$thread->id}@mail.stockbeat.app",
        'from' => 'jamie@example.com',
        'text' => 'Hello',
    ])->assertUnauthorized();
});

test('an unparseable to address is ignored without error', function () {
    test()->withToken('test-secret')->postJson('/hooks/email-inbound', [
        'to' => 'random@mail.stockbeat.app',
        'from' => 'jamie@example.com',
        'text' => 'Hello',
    ])->assertOk();
});
