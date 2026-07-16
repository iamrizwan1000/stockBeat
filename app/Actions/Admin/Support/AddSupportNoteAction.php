<?php

namespace App\Actions\Admin\Support;

use App\Models\AdminUser;
use App\Models\SupportMessage;
use App\Models\SupportThread;

/**
 * Internal notes (Plan §8.7.6: "invisible to user"). Deliberately not
 * broadcast to the user's `support-thread.{id}` channel or delivered via
 * push/email — only ever read by staff viewing the thread in admin.
 */
class AddSupportNoteAction
{
    public function handle(AdminUser $admin, SupportThread $thread, string $body): SupportMessage
    {
        return SupportMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SupportMessage::DIRECTION_NOTE,
            'admin_id' => $admin->id,
            'body' => $body,
            'created_at' => now(),
        ]);
    }
}
