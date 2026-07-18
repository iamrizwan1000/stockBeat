<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan §8.7: "Separate admin auth guard + mandatory 2FA" — flatly stated,
 * with no phased-rollout or grace-period language anywhere in §8.6/§8.7. So
 * this enforces immediately: any authenticated admin (any role — 2FA being
 * "mandatory" has no readonly/support/superadmin carve-out) who has never
 * confirmed 2FA (`two_factor_confirmed_at` is null) is confined to the
 * Security page until they do.
 *
 * Scoped to the whole authenticated `/admin` route group in routes/web.php,
 * the same way `EnsureUserIsNotSuspended` is scoped to its own route group
 * (registered as a named alias in bootstrap/app.php, applied inline on the
 * group) — see `EnsureAdminCanWrite` for the equivalent per-subgroup pattern
 * this mirrors, minus any role exemption.
 *
 * Does not touch Fortify's own login-time two-factor challenge: that flow
 * (entering a one-time code for an admin who already has 2FA confirmed)
 * happens on Fortify's own routes, before the session is authenticated —
 * this middleware only ever runs once an admin is already logged in, and
 * exempts only the Security page itself (where 2FA is set up) so a
 * not-yet-confirmed admin always has somewhere to go.
 */
class EnsureAdminHasTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var AdminUser|null $admin */
        $admin = $request->user('admin');

        if ($admin === null || $admin->two_factor_confirmed_at !== null) {
            return $next($request);
        }

        if ($request->routeIs('admin.security.index')) {
            return $next($request);
        }

        return redirect()
            ->route('admin.security.index')
            ->with('twoFactorRequired', true);
    }
}
