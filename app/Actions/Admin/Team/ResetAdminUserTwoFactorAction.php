<?php

namespace App\Actions\Admin\Team;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use Illuminate\Validation\ValidationException;

/**
 * Plan §8.7.8: "2FA reset." Fortify's `twoFactorAuthentication` feature is
 * intentionally disabled on the admin guard pending a self-service 2FA
 * settings UI (see `config/fortify.php`), so `two_factor_*` columns are
 * never populated through the app today. This action still exists as the
 * admin-side "clear a stuck 2FA state" capability the moment that UI ships
 * — clearing these columns is safe and correct regardless of how they got
 * set.
 */
class ResetAdminUserTwoFactorAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, AdminUser $target): AdminUser
    {
        if ($admin->role !== AdminUser::ROLE_SUPERADMIN) {
            throw ValidationException::withMessages(['admin' => 'Only a superadmin can reset another admin\'s 2FA.']);
        }

        if ($target->is($admin)) {
            throw ValidationException::withMessages(['admin' => 'You cannot reset your own 2FA — ask another superadmin.']);
        }

        $target->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->auditLog->handle($admin, 'admin_user.reset_2fa', AdminUser::class, $target->id, null, null);

        return $target;
    }
}
