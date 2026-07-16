<?php

namespace App\Actions\Admin\Team;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Plan §8.7.8: "manage admin users." Only a superadmin can create new admin
 * accounts — there's no self-registration or email-invite flow for the
 * admin guard (Plan's own note in `config/fortify.php`: "admins are
 * provisioned by a superadmin"), so the superadmin sets the initial
 * password directly here.
 */
class CreateAdminUserAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, array $data): AdminUser
    {
        if ($admin->role !== AdminUser::ROLE_SUPERADMIN) {
            throw ValidationException::withMessages(['role' => 'Only a superadmin can create admin users.']);
        }

        $newAdmin = AdminUser::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
        ]);

        $this->auditLog->handle($admin, 'admin_user.create', AdminUser::class, $newAdmin->id, null, [
            'name' => $newAdmin->name,
            'email' => $newAdmin->email,
            'role' => $newAdmin->role,
        ]);

        return $newAdmin;
    }
}
