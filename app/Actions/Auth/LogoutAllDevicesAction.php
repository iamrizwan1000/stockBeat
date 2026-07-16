<?php

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Revokes every Sanctum token for the user (Plan §10 `POST /auth/logout-all`)
 * — every device is signed out, including this one.
 */
class LogoutAllDevicesAction
{
    public function handle(User $user): int
    {
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        return $count;
    }
}
