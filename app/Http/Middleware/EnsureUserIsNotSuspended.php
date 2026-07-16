<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended user's tokens stay valid but the API rejects every request —
 * this is what makes the admin "suspend account" action (Plan §8.7.2) real.
 */
class EnsureUserIsNotSuspended
{
    /**
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user?->suspended_at !== null) {
            throw new AuthorizationException('This account has been suspended.');
        }

        return $next($request);
    }
}
