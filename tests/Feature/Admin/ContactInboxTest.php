<?php

use App\Mail\ContactReplyMail;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\ContactMessage;
use App\Models\ContactThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function contactThreadWithGuestMessage(): ContactThread
{
    $thread = ContactThread::factory()->create(['email' => 'jamie@example.com']);
    ContactMessage::factory()->create(['thread_id' => $thread->id, 'body' => 'How do I upgrade?']);

    return $thread;
}

test('the contact inbox requires admin authentication', function () {
    test()->get('/admin/contact-inbox')->assertRedirect('/admin/login');
});

test('an admin can list and filter contact threads by status', function () {
    $admin = AdminUser::factory()->create();
    ContactThread::factory()->create(['status' => ContactThread::STATUS_OPEN]);
    ContactThread::factory()->create(['status' => ContactThread::STATUS_CLOSED]);

    test()->actingAs($admin, 'admin')
        ->get('/admin/contact-inbox?status=open')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('threads', 1));
});

test('an admin can view a thread with its messages', function () {
    $admin = AdminUser::factory()->create();
    $thread = contactThreadWithGuestMessage();

    test()->actingAs($admin, 'admin')
        ->get("/admin/contact-inbox/{$thread->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('thread.email', 'jamie@example.com')
            ->has('messages', 1));
});

test('replying creates a staff message, updates thread status, and emails the guest — not any authenticated user', function () {
    Mail::fake();
    $admin = AdminUser::factory()->create();
    $thread = contactThreadWithGuestMessage();

    test()->actingAs($admin, 'admin')
        ->post("/admin/contact-inbox/{$thread->id}/reply", ['body' => 'Here is how to upgrade.'])
        ->assertRedirect();

    $thread->refresh();
    expect($thread->status)->toBe(ContactThread::STATUS_REPLIED);

    $staffMessage = ContactMessage::query()->where('thread_id', $thread->id)->where('direction', ContactMessage::DIRECTION_STAFF)->first();
    expect($staffMessage)->not->toBeNull();
    expect($staffMessage->admin_id)->toBe($admin->id);
    expect($staffMessage->body)->toBe('Here is how to upgrade.');

    Mail::assertQueued(ContactReplyMail::class, function (ContactReplyMail $mail) use ($thread) {
        return $mail->hasTo($thread->email) && $mail->body === 'Here is how to upgrade.';
    });

    expect(AdminAuditLog::query()->where('action', 'contact.reply')->where('target_id', $thread->id)->exists())->toBeTrue();
});

test('a readonly admin cannot reply', function () {
    $admin = AdminUser::factory()->readonly()->create();
    $thread = contactThreadWithGuestMessage();

    test()->actingAs($admin, 'admin')
        ->post("/admin/contact-inbox/{$thread->id}/reply", ['body' => 'Hello'])
        ->assertForbidden();

    expect(ContactMessage::query()->where('thread_id', $thread->id)->count())->toBe(1);
});
