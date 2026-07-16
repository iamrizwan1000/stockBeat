<?php

namespace App\Actions\Admin;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Suspending also revokes active tokens — a suspended user shouldn't stay
 * signed in on a device just because the API check happens on the next
 * request (Plan §8.7.2 "pause/suspend account").
 */
class SuspendAccountAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
        private readonly ForceLogoutAction $forceLogout,
    ) {}

    public function handle(AdminUser $admin, User $user): User
    {
        $before = ['suspended_at' => $user->suspended_at?->toIso8601String()];

        $user->suspended_at = Carbon::now();
        $user->save();

        $this->forceLogout->handle($admin, $user);

        $this->auditLog->handle($admin, 'customer.suspend', User::class, $user->id, $before, [
            'suspended_at' => $user->suspended_at->toIso8601String(),
        ]);

        return $user;
    }
}
