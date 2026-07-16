<?php

use App\Events\SupportMessageSent;
use App\Mail\SupportReplyMail;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\CannedReply;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('the support inbox requires admin authentication', function () {
    test()->get('/admin/support')->assertRedirect('/admin/login');
});

test('threads can be filtered by status and unassigned', function () {
    $admin = AdminUser::factory()->create();
    $open = SupportThread::factory()->create(['status' => SupportThread::STATUS_OPEN, 'assigned_admin_id' => null]);
    $resolved = SupportThread::factory()->create(['status' => SupportThread::STATUS_RESOLVED, 'assigned_admin_id' => $admin->id]);

    test()->actingAs($admin, 'admin')
        ->get('/admin/support?status=open&unassigned=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('threads', 1)
            ->where('threads.0.id', $open->id)
        );
});

test('a staff reply is delivered via websocket, push, and email, and moves the thread to awaiting_user', function () {
    Event::fake([SupportMessageSent::class]);
    Mail::fake();
    $admin = AdminUser::factory()->create();
    $thread = SupportThread::factory()->create(['status' => SupportThread::STATUS_OPEN]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/support/{$thread->id}/reply", ['body' => 'We are looking into it.'])
        ->assertRedirect();

    $thread->refresh();
    expect($thread->status)->toBe(SupportThread::STATUS_AWAITING_USER);

    $message = SupportMessage::query()->where('thread_id', $thread->id)->where('direction', SupportMessage::DIRECTION_STAFF)->firstOrFail();
    expect($message->admin_id)->toBe($admin->id);
    expect($message->delivered_via)->toBe(['websocket' => true, 'push' => 'no_devices', 'email' => 'queued']);

    Event::assertDispatched(SupportMessageSent::class);
    Mail::assertQueued(SupportReplyMail::class);
    expect(AdminAuditLog::query()->where('action', 'support.reply')->where('admin_id', $admin->id)->exists())->toBeTrue();
});

test('an internal note is stored but never broadcast', function () {
    Event::fake([SupportMessageSent::class]);
    $admin = AdminUser::factory()->create();
    $thread = SupportThread::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post("/admin/support/{$thread->id}/notes", ['body' => 'Suspicious refund pattern, watch this one'])
        ->assertRedirect();

    $note = SupportMessage::query()->where('thread_id', $thread->id)->where('direction', SupportMessage::DIRECTION_NOTE)->firstOrFail();
    expect($note->admin_id)->toBe($admin->id);
    Event::assertNotDispatched(SupportMessageSent::class);
});

test('a thread can be assigned and unassigned', function () {
    $admin = AdminUser::factory()->create();
    $thread = SupportThread::factory()->create(['assigned_admin_id' => null]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/support/{$thread->id}/assign", ['assigned_admin_id' => $admin->id])
        ->assertRedirect();
    expect($thread->fresh()->assigned_admin_id)->toBe($admin->id);

    test()->actingAs($admin, 'admin')
        ->post("/admin/support/{$thread->id}/assign", ['assigned_admin_id' => null])
        ->assertRedirect();
    expect($thread->fresh()->assigned_admin_id)->toBeNull();
});

test('a thread can be marked resolved', function () {
    $admin = AdminUser::factory()->create();
    $thread = SupportThread::factory()->create(['status' => SupportThread::STATUS_OPEN]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/support/{$thread->id}/resolve")
        ->assertRedirect();

    expect($thread->fresh()->status)->toBe(SupportThread::STATUS_RESOLVED);
});

test('a readonly admin cannot reply, assign, or resolve', function () {
    $admin = AdminUser::factory()->readonly()->create();
    $thread = SupportThread::factory()->create();

    test()->actingAs($admin, 'admin')->post("/admin/support/{$thread->id}/reply", ['body' => 'x'])->assertForbidden();
    test()->actingAs($admin, 'admin')->post("/admin/support/{$thread->id}/resolve")->assertForbidden();
});

test('canned replies can be created, updated, and deleted', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/canned-replies', ['title' => 'Sync issue', 'body' => 'Try disconnecting and reconnecting your store.'])
        ->assertRedirect();

    $reply = CannedReply::query()->where('title', 'Sync issue')->firstOrFail();

    test()->actingAs($admin, 'admin')
        ->put("/admin/canned-replies/{$reply->id}", ['title' => 'Sync issue v2', 'body' => 'Updated body.'])
        ->assertRedirect();
    expect($reply->fresh()->title)->toBe('Sync issue v2');

    test()->actingAs($admin, 'admin')
        ->delete("/admin/canned-replies/{$reply->id}")
        ->assertRedirect();
    expect(CannedReply::query()->find($reply->id))->toBeNull();
});
