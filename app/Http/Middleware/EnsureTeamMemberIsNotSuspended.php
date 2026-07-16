<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan §6.4 downgrade freeze: "extra team members lose access (memberships
 * kept, suspended)". Distinct from `EnsureUserIsNotSuspended` — that's a
 * whole account suspended by admin action; this is one membership frozen
 * by a team-level downgrade while the user's own account stays fine (they
 * might own or belong to other teams, though today's one-team-per-user
 * scope means in practice they'd have no team at all until re-upgrade).
 */
class EnsureTeamMemberIsNotSuspended
{
    /**
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->currentTeamMember()?->suspended_at !== null) {
            throw new AuthorizationException('Your access to this team has been suspended pending upgrade.');
        }

        return $next($request);
    }
}
