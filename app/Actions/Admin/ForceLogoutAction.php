<?php

namespace App\Actions\Admin;

use App\Models\AdminUser;
use App\Models\User;

class ForceLogoutAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, User $user): int
    {
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        $this->auditLog->handle($admin, 'customer.force_logout', User::class, $user->id, null, [
            'tokens_revoked' => $count,
        ]);

        return $count;
    }
}
