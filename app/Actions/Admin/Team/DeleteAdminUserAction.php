<?php

namespace App\Actions\Admin\Team;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use Illuminate\Validation\ValidationException;

class DeleteAdminUserAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, AdminUser $target): void
    {
        if ($admin->role !== AdminUser::ROLE_SUPERADMIN) {
            throw ValidationException::withMessages(['admin' => 'Only a superadmin can remove admin users.']);
        }

        if ($target->is($admin)) {
            throw ValidationException::withMessages(['admin' => 'You cannot remove your own admin account.']);
        }

        $this->auditLog->handle($admin, 'admin_user.delete', AdminUser::class, $target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'role' => $target->role,
        ], null);

        $target->delete();
    }
}
