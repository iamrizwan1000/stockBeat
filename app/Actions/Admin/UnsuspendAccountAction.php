<?php

namespace App\Actions\Admin;

use App\Models\AdminUser;
use App\Models\User;

class UnsuspendAccountAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, User $user): User
    {
        $before = ['suspended_at' => $user->suspended_at?->toIso8601String()];

        $user->suspended_at = null;
        $user->save();

        $this->auditLog->handle($admin, 'customer.unsuspend', User::class, $user->id, $before, [
            'suspended_at' => null,
        ]);

        return $user;
    }
}
