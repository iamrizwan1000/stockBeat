<?php

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Revokes only the token used for this request (Plan §10 `POST /auth/logout`)
 * — other devices stay signed in.
 */
class LogoutAction
{
    public function handle(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
