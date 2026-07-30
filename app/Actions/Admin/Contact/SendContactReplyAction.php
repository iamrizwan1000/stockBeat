<?php

namespace App\Actions\Admin\Contact;

use App\Actions\Admin\AuditLogAction;
use App\Mail\ContactReplyMail;
use App\Models\AdminUser;
use App\Models\ContactMessage;
use App\Models\ContactThread;
use Illuminate\Support\Facades\Mail;

/**
 * Staff reply to a guest contact inquiry — the guest-facing counterpart to
 * `SendStaffReplyAction`, deliberately simpler: no push/WebSocket delivery
 * (a guest has no device/session to reach), email is the only channel.
 */
class SendContactReplyAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, ContactThread $thread, string $body): ContactMessage
    {
        $message = ContactMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => ContactMessage::DIRECTION_STAFF,
            'admin_id' => $admin->id,
            'body' => $body,
            'created_at' => now(),
        ]);

        $thread->update(['status' => ContactThread::STATUS_REPLIED, 'last_message_at' => $message->created_at]);

        Mail::to($thread->email)->queue(new ContactReplyMail($body));

        $this->auditLog->handle($admin, 'contact.reply', ContactThread::class, $thread->id, null, [
            'message_id' => $message->id,
        ]);

        return $message;
    }
}
