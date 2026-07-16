<?php

namespace App\Actions\Billing;

use App\Models\Rule;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\TeamMember;

/**
 * "Springs back on upgrade" (Plan §6.4) — the reverse of
 * `ApplyDowngradeFreezeAction`. Only ever touches rows that action itself
 * marked (`paused_at`/`auto_disabled_at`/`suspended_at` not null) — a rule
 * the user had disabled themselves *before* the downgrade stays disabled,
 * a store they'd disconnected stays disconnected. Restores up to the *new*
 * plan's limits, oldest-first, so upgrading to a lower-but-still-paid tier
 * than before the freeze doesn't restore more than that tier allows —
 * anything left over stays frozen, ready for the next upgrade.
 */
class ReverseDowngradeFreezeAction
{
    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    public function handle(Team $team): void
    {
        $limits = $this->resolveEntitlements->handle($team)['limits'];

        $this->reactivateStores($team, $limits['max_stores'] ?? null);
        $this->reenableRules($team, $limits['max_rules'] ?? null);
        $this->unsuspendMembers($team, (int) ($limits['team_seats'] ?? 1));
    }

    private function reactivateStores(Team $team, ?int $maxStores): void
    {
        $paused = StoreConnection::query()
            ->where('team_id', $team->id)
            ->where('status', StoreConnection::STATUS_PAUSED)
            ->orderBy('created_at')
            ->get();

        $alreadyActive = StoreConnection::query()
            ->where('team_id', $team->id)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->count();

        $slots = $maxStores === null ? $paused->count() : max(0, $maxStores - $alreadyActive);

        $paused->take($slots)->each(fn (StoreConnection $connection) => $connection->update([
            'status' => StoreConnection::STATUS_ACTIVE,
            'paused_at' => null,
        ]));
    }

    private function reenableRules(Team $team, ?int $maxRules): void
    {
        $disabled = Rule::query()
            ->where('team_id', $team->id)
            ->whereNotNull('auto_disabled_at')
            ->orderBy('created_at')
            ->get();

        $alreadyEnabled = Rule::query()->where('team_id', $team->id)->where('enabled', true)->count();

        $slots = $maxRules === null ? $disabled->count() : max(0, $maxRules - $alreadyEnabled);

        $disabled->take($slots)->each(fn (Rule $rule) => $rule->update([
            'enabled' => true,
            'auto_disabled_at' => null,
        ]));
    }

    private function unsuspendMembers(Team $team, int $teamSeats): void
    {
        $suspended = TeamMember::query()
            ->where('team_id', $team->id)
            ->whereNotNull('suspended_at')
            ->orderBy('created_at')
            ->get();

        $alreadyActive = TeamMember::query()->where('team_id', $team->id)->whereNull('suspended_at')->count();

        $slots = max(0, $teamSeats - $alreadyActive);

        $suspended->take($slots)->each(fn (TeamMember $member) => $member->update(['suspended_at' => null]));
    }
}
