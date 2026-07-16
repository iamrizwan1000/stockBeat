<?php

namespace App\Actions\Account;

use App\Models\User;

/**
 * GDPR account deletion (Plan §4.8), soft-delete + grace period: revokes
 * every token immediately, soft-deletes the user, and — if they're a team
 * owner — soft-deletes the whole team too, since every other domain table
 * hangs off `team_id` and an owner's departure takes their business with
 * it. Other members' rows are left alone; their `Team::belongsTo` relation
 * simply stops resolving once the team is trashed, so they lose access
 * without their own account being touched. A scheduled command
 * (`accounts:purge-deleted`) hard-deletes both after a 30-day grace
 * period; there's no restore endpoint yet (re-registering during the
 * grace period doesn't recover the old account, it just can't happen
 * since the email is still attached to the soft-deleted row).
 */
class RequestAccountDeletionAction
{
    public function handle(User $user): void
    {
        $ownedTeam = $user->ownedTeam;

        $user->tokens()->delete();
        $user->delete();

        $ownedTeam?->delete();
    }
}
