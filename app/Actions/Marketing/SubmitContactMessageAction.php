<?php

namespace App\Actions\Marketing;

use App\Models\ContactMessage;
use App\Models\ContactThread;
use App\Support\Concurrency\IdempotencyGuard;

/**
 * Creates a new guest contact inquiry — always a fresh thread (there's no
 * login/session to resume a prior one against), unlike `SupportThread`
 * which is "one thread per user, resumed across visits."
 *
 * Guarded against a double-tap on a slow connection creating two duplicate
 * threads — keyed by email+the exact message body, so a genuinely
 * different inquiry moments later is never blocked.
 */
class SubmitContactMessageAction
{
    public function handle(string $name, string $email, ?string $subject, string $message): ?ContactThread
    {
        return IdempotencyGuard::once('contact-message:'.$email.':'.md5($message), 15, function () use ($name, $email, $subject, $message) {
            $thread = ContactThread::query()->create([
                'name' => $name,
                'email' => $email,
                'subject' => $subject,
                'status' => ContactThread::STATUS_OPEN,
                'last_message_at' => now(),
            ]);

            ContactMessage::query()->create([
                'thread_id' => $thread->id,
                'direction' => ContactMessage::DIRECTION_GUEST,
                'body' => $message,
            ]);

            return $thread;
        });
    }
}
