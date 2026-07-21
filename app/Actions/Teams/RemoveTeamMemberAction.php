<?php

namespace App\Actions\Teams;

use App\Models\TeamMember;
use Illuminate\Validation\ValidationException;

/**
 * Removes a member from a team (Plan §4.7). A hard delete of the pivot
 * row, not a soft-delete — nothing else references `team_members.id` as a
 * foreign key, and every domain row a removed member touched (assigned
 * inbox threads, rules they created, etc.) references `users.id`
 * (`nullOnDelete`/nullable), which is untouched here. The user's own
 * account is never affected; only their membership in this specific team.
 *
 * The member's own next `GET /me` call resolves `currentTeam()` to `null`
 * and reports `needs_profile_setup: true` — the same graceful path a
 * brand-new user takes, already handled client-side. Calling
 * `POST /profile/setup` again from that state spins up a fresh owned team
 * for them (`SetupProfileAction`), which is the intended soft landing, not
 * a bug to guard against here.
 */
class RemoveTeamMemberAction
{
    public function handle(TeamMember $member): void
    {
        if ($member->role === TeamMember::ROLE_OWNER) {
            throw ValidationException::withMessages([
                'member' => "The team owner can't be removed.",
            ]);
        }

        $member->delete();
    }
}
