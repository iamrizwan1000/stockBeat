<?php

namespace App\Actions\Admin\Support;

use App\Actions\Admin\AuditLogAction;
use App\Actions\Notifications\SendPushNotificationAction;
use App\Events\SupportMessageSent;
use App\Mail\SupportReplyMail;
use App\Models\AdminUser;
use App\Models\Notification;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Staff reply to a user's support thread (Plan §4.9/§8.7.6). Delivery is
 * always real-time (WebSocket) + push + email — the spec's "push notification
 * + full reply by email when they're not [in-app]" is simplified here to
 * "always send both," since there's no presence-detection mechanism to know
 * whether the user is actively viewing the thread right now; the WebSocket
 * broadcast covers the in-app case, push/email cover everything else.
 */
class SendStaffReplyAction
{
    public function __construct(
        private readonly SendPushNotificationAction $sendPush,
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, SupportThread $thread, string $body): SupportMessage
    {
        $message = SupportMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SupportMessage::DIRECTION_STAFF,
            'admin_id' => $admin->id,
            'body' => $body,
            'created_at' => now(),
        ]);

        $thread->update(['status' => SupportThread::STATUS_AWAITING_USER, 'last_message_at' => $message->created_at]);

        $user = $thread->user;
        $pushStatus = $this->sendPush->handle(
            $user,
            'New reply from support',
            Str::limit($body, 100),
            ['thread_id' => $thread->id],
            Notification::TYPE_SUPPORT_REPLY,
        );

        Mail::to($user->email)->queue(new SupportReplyMail($body));

        $message->update(['delivered_via' => ['websocket' => true, 'push' => $pushStatus, 'email' => 'queued']]);

        broadcast(new SupportMessageSent($message))->toOthers();

        $this->auditLog->handle($admin, 'support.reply', SupportThread::class, $thread->id, null, [
            'message_id' => $message->id,
        ]);

        return $message;
    }
}
