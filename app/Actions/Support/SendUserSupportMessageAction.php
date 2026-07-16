<?php

namespace App\Actions\Support;

use App\Events\SupportMessageSent;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Models\User;

/**
 * A user's own message into their support thread (Plan §4.9). Reopens a
 * resolved thread — the user replying is exactly what "resolved" should
 * never block.
 */
class SendUserSupportMessageAction
{
    public function __construct(
        private readonly GetOrCreateSupportThreadAction $getOrCreateThread,
    ) {}

    public function handle(User $user, string $body): SupportMessage
    {
        $thread = $this->getOrCreateThread->handle($user);

        $message = SupportMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SupportMessage::DIRECTION_USER,
            'body' => $body,
            'created_at' => now(),
        ]);

        $thread->update([
            'status' => SupportThread::STATUS_OPEN,
            'last_message_at' => $message->created_at,
        ]);

        broadcast(new SupportMessageSent($message))->toOthers();

        return $message;
    }
}
