<?php

use App\Models\AdminUser;
use App\Models\SupportThread;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Support chat (Plan §4.9/§8.7.6). `$user` resolves from whichever guard the
 * `auth:admin,sanctum` broadcasting-auth middleware matched (bootstrap/app.php)
 * — any staff admin may subscribe to any thread; the owning end user may
 * subscribe only to their own.
 */
Broadcast::channel('support-thread.{threadId}', function ($user, int $threadId) {
    if ($user instanceof AdminUser) {
        return true;
    }

    if ($user instanceof User) {
        return SupportThread::query()->where('id', $threadId)->where('user_id', $user->id)->exists();
    }

    return false;
});
