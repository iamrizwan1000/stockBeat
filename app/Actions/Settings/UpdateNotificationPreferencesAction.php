<?php

namespace App\Actions\Settings;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Upserts the caller's personal notification preferences (Plan §4.8).
 */
class UpdateNotificationPreferencesAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data): NotificationPreference
    {
        $preference = NotificationPreference::query()->firstOrNew(['user_id' => $user->id]);
        $preference->fill(['user_id' => $user->id, ...$data]);
        $preference->save();

        return $preference;
    }
}
