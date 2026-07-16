<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates write actions by the caller's TeamMember role (Plan §4.7: Manager
 * "all actions", Agent "view + inbox only", Viewer read-only). Applied only
 * to mutation routes — read routes are open to every role. A user with no
 * team membership yet is let through untouched — that's a "complete profile
 * setup first" 422 from the controller, not a role problem, so it must
 * produce that message rather than a generic 403 here.
 */
class EnsureTeamRole
{
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        /** @var User $user */
        $user = $request->user();

        $role = $user->currentTeamMember()?->role;

        if ($role !== null && ! in_array($role, $allowedRoles, true)) {
            return ApiResponse::error("You don't have permission to do that.", status: 403);
        }

        return $next($request);
    }
}
