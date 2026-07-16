<?php

namespace App\Actions\Inbox;

use App\Models\InboxThread;
use App\Models\User;

class AssignInboxThreadAction
{
    public function handle(InboxThread $thread, ?User $assignee): InboxThread
    {
        $thread->update(['assigned_to' => $assignee?->id]);

        return $thread;
    }
}
