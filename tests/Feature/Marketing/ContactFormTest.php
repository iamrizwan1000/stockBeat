<?php

use App\Models\ContactMessage;
use App\Models\ContactThread;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('submitting the contact form creates a thread and its first guest message', function () {
    test()->post('/contact', [
        'name' => 'Jamie Rivera',
        'email' => 'jamie@example.com',
        'subject' => 'Question about billing',
        'message' => 'How do I upgrade my plan?',
    ])->assertRedirect();

    $thread = ContactThread::query()->where('email', 'jamie@example.com')->first();
    expect($thread)->not->toBeNull();
    expect($thread->name)->toBe('Jamie Rivera');
    expect($thread->subject)->toBe('Question about billing');
    expect($thread->status)->toBe(ContactThread::STATUS_OPEN);

    $message = ContactMessage::query()->where('thread_id', $thread->id)->first();
    expect($message->direction)->toBe(ContactMessage::DIRECTION_GUEST);
    expect($message->body)->toBe('How do I upgrade my plan?');
});

test('submitting without a required field fails validation', function () {
    test()->post('/contact', [
        'name' => 'Jamie Rivera',
        'email' => 'not-an-email',
        'message' => 'Hello',
    ])->assertSessionHasErrors('email');

    expect(ContactThread::query()->count())->toBe(0);
});

test('the subject is optional', function () {
    test()->post('/contact', [
        'name' => 'Jamie Rivera',
        'email' => 'jamie@example.com',
        'message' => 'Hello',
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    expect(ContactThread::query()->first()->subject)->toBeNull();
});
