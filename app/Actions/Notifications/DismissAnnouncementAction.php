<?php

namespace App\Actions\Notifications;

use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Records that a user closed an announcement banner (Plan §8.7.5-adjacent),
 * so it stops appearing in `GetActiveAnnouncementsForUserAction`'s results
 * for them specifically — every other targeted user still sees it.
 * Idempotent: dismissing an already-dismissed announcement is a no-op, not
 * an error, since a client retry or a double-tap shouldn't fail.
 */
class DismissAnnouncementAction
{
    public function handle(User $user, Announcement $announcement): void
    {
        if (! $announcement->dismissible) {
            throw ValidationException::withMessages([
                'announcement' => ["This announcement can't be dismissed."],
            ]);
        }

        AnnouncementDismissal::query()->firstOrCreate(
            ['user_id' => $user->id, 'announcement_id' => $announcement->id],
            ['dismissed_at' => now()],
        );
    }
}
