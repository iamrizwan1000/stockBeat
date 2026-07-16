<?php

namespace App\Actions\Support;

use App\Models\SupportThread;
use App\Models\User;

/**
 * Plan §4.9: "Every user (including Free) gets a Help entry... opening a
 * live support chat." One thread per user, resumed rather than recreated —
 * matches how a real support inbox behaves.
 */
class GetOrCreateSupportThreadAction
{
    public function handle(User $user): SupportThread
    {
        return SupportThread::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['status' => SupportThread::STATUS_OPEN, 'last_message_at' => now()],
        );
    }
}
