<?php

namespace App\Actions\Notifications;

use App\Models\BroadcastDelivery;
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

        $matchedIds = (clone $query)->pluck('id');

        $count = $query->update(['read_at' => now()]);

        // Push/banner broadcasts have no native "opened" event (Plan
        // §8.7.5 open-tracking gap #1) — the honest proxy is "recipient
        // marked the linked in-app notification read", via the
        // `notification_id` link `SendBroadcastToRecipientJob` sets on the
        // delivery row. This is best-effort, not a literal tap/open event.
        if ($matchedIds->isNotEmpty()) {
            BroadcastDelivery::query()
                ->whereIn('notification_id', $matchedIds)
                ->whereNull('opened_at')
                ->update(['opened_at' => now()]);
        }

        return $count;
    }
}
