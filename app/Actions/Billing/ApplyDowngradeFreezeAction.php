<?php

namespace App\Actions\Billing;

use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Rule;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\TeamMember;

/**
 * Plan §6.4 "downgrade freezes, never destroys" — the moment a trial or paid
 * subscription lapses (same logic either way, per the spec). Every mutation
 * here is reversible by `ReverseDowngradeFreezeAction`, which only restores
 * what this action itself touched (tracked via `paused_at`/`auto_disabled_at`/
 * `suspended_at`), never something the user had already deliberately turned
 * off before the downgrade.
 *
 * Deliberately idempotent: calling this twice on an already-frozen team is a
 * no-op the second time (nothing left to pause/disable/suspend), since the
 * trial-expiry and RevenueCat-webhook call sites can't guarantee exactly-once.
 */
class ApplyDowngradeFreezeAction
{
    public function handle(Team $team): void
    {
        $this->pauseExtraStores($team);
        $this->disableRules($team);
        $this->suspendExtraMembers($team);
    }

    /**
     * The single oldest non-disconnected connection stays active
     * ("first-connected store stays active" — Plan §6.4); every other one
     * is paused, not disconnected, so credentials and webhook registration
     * survive untouched for the resume.
     */
    private function pauseExtraStores(Team $team): void
    {
        $connections = StoreConnection::query()
            ->where('team_id', $team->id)
            ->where('status', '!=', StoreConnection::STATUS_DISCONNECTED)
            ->orderBy('created_at')
            ->get();

        $connections->skip(1)->each(function (StoreConnection $connection) {
            if ($connection->status === StoreConnection::STATUS_PAUSED) {
                return;
            }

            $connection->update(['status' => StoreConnection::STATUS_PAUSED, 'paused_at' => now()]);
        });
    }

    /**
     * All currently-enabled rules — every row in `rules` is a paid-plan
     * custom rule (the free-tier presets aren't `rules` rows at all, see
     * `SendMorningDigestAction`/new-order push, which run independently of
     * this table), so there's no preset/custom split to preserve here.
     */
    private function disableRules(Team $team): void
    {
        Rule::query()
            ->where('team_id', $team->id)
            ->where('enabled', true)
            ->update(['enabled' => false, 'auto_disabled_at' => now()]);
    }

    /**
     * The earliest N members (by join order) up to the *free* plan's
     * admin-editable `team_seats` limit stay active — everyone else is
     * suspended. The owner's own membership is never touched (mirrors
     * `UpdateTeamMemberAction`'s existing "owner's row is immutable" rule).
     */
    private function suspendExtraMembers(Team $team): void
    {
        $freeSeatLimit = (int) (Plan::query()->where('key', Plan::FREE)->first()?->limitsArray()[PlanLimit::TEAM_SEATS] ?? 1);

        $members = TeamMember::query()
            ->where('team_id', $team->id)
            ->orderBy('created_at')
            ->get();

        $members->skip($freeSeatLimit)->each(function (TeamMember $member) {
            if ($member->role === TeamMember::ROLE_OWNER || $member->suspended_at !== null) {
                return;
            }

            $member->update(['suspended_at' => now()]);
        });
    }
}
