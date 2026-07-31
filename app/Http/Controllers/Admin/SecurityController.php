<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service, optional 2FA management — not enforced (the mandatory
 * gate this page originally existed for, EnsureAdminHasTwoFactorEnabled,
 * has been removed). The actual enable/confirm/disable/QR-code/recovery-
 * code endpoints are Fortify's own — this controller only renders the
 * page that drives them.
 */
class SecurityController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        return Inertia::render('admin/security/index', [
            'twoFactorEnabled' => $admin->two_factor_confirmed_at !== null,
        ]);
    }
}
