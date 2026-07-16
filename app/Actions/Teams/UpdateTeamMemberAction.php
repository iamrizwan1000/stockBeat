<?php

namespace App\Actions\Teams;

use App\Models\TeamMember;
use Illuminate\Validation\ValidationException;

/**
 * Updates a member's role and/or store visibility (Plan §4.7). The owner's
 * own membership is immutable via this path — ownership only ever
 * transfers by creating a new team, which isn't a Plan §4.7 feature.
 */
class UpdateTeamMemberAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(TeamMember $member, array $data): TeamMember
    {
        if ($member->role === TeamMember::ROLE_OWNER) {
            throw ValidationException::withMessages([
                'role' => "The team owner's role can't be changed.",
            ]);
        }

        $member->fill($data);
        $member->save();

        return $member;
    }
}
