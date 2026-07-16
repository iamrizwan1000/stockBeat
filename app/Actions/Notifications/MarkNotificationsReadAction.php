<?php

namespace App\Actions\Notifications;

use App\Models\Notification;
use App\Models\User;

class MarkNotificationsReadAction
{
    /**
     * @param  array<int, int>|null  $ids  Specific notification IDs, or null to mark all unread.
     */
    public function handle(User $user, ?array $ids): int
    {
        $query = Notification::query()->where('user_id', $user->id)->whereNull('read_at');

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->update(['read_at' => now()]);
    }
}
