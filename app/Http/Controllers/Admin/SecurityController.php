<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service 2FA management (Plan §8.7 "Separate admin auth guard +
 * mandatory 2FA"). The actual enable/confirm/disable/QR-code/recovery-code
 * endpoints are Fortify's own — this controller only renders the page that
 * drives them.
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
