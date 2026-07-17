<?php

namespace App\Actions\Support;

use App\Models\SupportThread;
use Illuminate\Validation\ValidationException;

/**
 * Records the 👍/👎 CSAT rating (Plan §4.9/§8.7.6 "CSAT (optional 👍/👎
 * after resolve)") a user leaves on their support thread. Only allowed
 * once a thread is actually resolved, and only once per resolution — a
 * later message reopens the thread (`SendUserSupportMessageAction`) but
 * doesn't clear a rating already given for the prior resolution.
 */
class SubmitSupportCsatAction
{
    public function handle(SupportThread $thread, int $rating): SupportThread
    {
        if ($thread->status !== SupportThread::STATUS_RESOLVED) {
            throw ValidationException::withMessages(['rating' => ['This thread is not resolved yet.']]);
        }

        if ($thread->csat !== null) {
            throw ValidationException::withMessages(['rating' => ['You already rated this conversation.']]);
        }

        $thread->update(['csat' => $rating]);

        return $thread;
    }
}
