<?php

namespace App\Actions\Teams;

use App\Models\TeamInvite;
use App\Models\TeamMember;
use App\Models\User;

/**
 * Auto-redeems a pending invite matched by email during onboarding (Plan
 * §4.7 has no separate "accept invite" endpoint — joining happens the
 * moment the invited person signs in with the invited address). Only
 * applies to a user with no existing team membership: the mobile app has
 * no team-switcher, so a user who already belongs to a team keeps it, and
 * their invite stays pending until they're free to redeem it (or it's
 * revoked/expires).
 */
class RedeemTeamInviteAction
{
    public function handle(User $user): bool
    {
        if ($user->teamMemberships()->exists()) {
            return false;
        }

        $invite = TeamInvite::query()
            ->where('email', $user->email)
            ->where('status', TeamInvite::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($invite === null) {
            return false;
        }

        TeamMember::query()->create([
            'team_id' => $invite->team_id,
            'user_id' => $user->id,
            'role' => $invite->role,
            'store_visibility' => $invite->store_visibility,
        ]);

        $invite->update([
            'status' => TeamInvite::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);

        return true;
    }
}
