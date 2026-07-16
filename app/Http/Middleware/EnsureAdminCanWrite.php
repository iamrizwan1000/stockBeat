<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan §8.7: the "readonly" admin role sees dashboards only — it must
 * never be able to perform a write action.
 */
class EnsureAdminCanWrite
{
    /**
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        if ($admin->role === AdminUser::ROLE_READONLY) {
            throw new AuthorizationException('Read-only admins cannot perform this action.');
        }

        return $next($request);
    }
}
