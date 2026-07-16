<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Team\CreateAdminUserAction;
use App\Actions\Admin\Team\DeleteAdminUserAction;
use App\Actions\Admin\Team\ResetAdminUserTwoFactorAction;
use App\Actions\Admin\Team\UpdateAdminUserRoleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminUserRequest;
use App\Http\Requests\Admin\UpdateAdminUserRoleRequest;
use App\Models\AdminUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function index(): Response
    {
        $admins = AdminUser::query()
            ->orderBy('name')
            ->get()
            ->map(fn (AdminUser $adminUser) => [
                'id' => $adminUser->id,
                'name' => $adminUser->name,
                'email' => $adminUser->email,
                'role' => $adminUser->role,
                'two_factor_enabled' => $adminUser->two_factor_confirmed_at !== null,
                'created_at' => $adminUser->created_at,
            ]);

        return Inertia::render('admin/team/index', ['admins' => $admins]);
    }

    public function store(CreateAdminUserRequest $request, CreateAdminUserAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $request->validated());

        return back()->with('status', 'Admin user created.');
    }

    public function updateRole(UpdateAdminUserRoleRequest $request, AdminUser $adminUser, UpdateAdminUserRoleAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $adminUser, $request->string('role')->toString());

        return back()->with('status', 'Role updated.');
    }

    public function resetTwoFactor(Request $request, AdminUser $adminUser, ResetAdminUserTwoFactorAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $adminUser);

        return back()->with('status', '2FA reset for this admin.');
    }

    public function destroy(Request $request, AdminUser $adminUser, DeleteAdminUserAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $adminUser);

        return back()->with('status', 'Admin user removed.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
