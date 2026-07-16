<?php

namespace App\Actions\Admin\Team;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use Illuminate\Validation\ValidationException;

class UpdateAdminUserRoleAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, AdminUser $target, string $role): AdminUser
    {
        if ($admin->role !== AdminUser::ROLE_SUPERADMIN) {
            throw ValidationException::withMessages(['role' => 'Only a superadmin can change admin roles.']);
        }

        if ($target->is($admin)) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
        }

        $before = ['role' => $target->role];

        $target->update(['role' => $role]);

        $this->auditLog->handle($admin, 'admin_user.update_role', AdminUser::class, $target->id, $before, ['role' => $role]);

        return $target;
    }
}
