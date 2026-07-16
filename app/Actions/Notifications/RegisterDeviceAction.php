<?php

namespace App\Actions\Notifications;

use App\Models\Device;
use App\Models\User;

/**
 * Registers (or refreshes) a push token for the authenticated user (Plan
 * §10 `POST /devices`). Keyed on (user_id, push_token) so relaunching the
 * app with the same token updates `last_seen_at` instead of duplicating
 * the row.
 */
class RegisterDeviceAction
{
    public function handle(User $user, string $platform, string $pushToken): Device
    {
        return Device::query()->updateOrCreate(
            ['user_id' => $user->id, 'push_token' => $pushToken],
            ['platform' => $platform, 'last_seen_at' => now()],
        );
    }
}
