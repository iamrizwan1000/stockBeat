<?php

namespace App\Actions\FeatureFlags;

use App\Models\FeatureFlag;
use App\Models\Team;

/**
 * Plan §8.7.3 / §9: evaluates a feature flag for a given team.
 *
 * Rules (safe-default order matters):
 *  1. Unknown flag key -> false (a flag that doesn't exist can't be "on").
 *  2. Master `enabled` off -> false, regardless of rollout/allow-list.
 *  3. Team explicitly listed in `enabled_for_team_ids` -> true (bypasses
 *     the percentage bucket — used for internal testing/dogfooding).
 *  4. Otherwise, deterministically bucket the team into 0-99 via a stable
 *     hash of `(flag_key, team_id)` and compare against `rollout_percentage`.
 *
 * The bucketing is intentionally a simple "bucket < percentage" comparison:
 * a team's bucket never changes for a given flag, so raising the
 * percentage can only ever *add* teams (every team already included at a
 * lower percentage has a bucket below that percentage, which remains true
 * below any higher percentage too) and never removes one.
 */
class IsFeatureEnabledForTeamAction
{
    public function handle(string $key, Team $team): bool
    {
        $flag = FeatureFlag::query()->where('key', $key)->first();

        if ($flag === null) {
            return false;
        }

        return $this->evaluate($flag, $team);
    }

    /**
     * Evaluate an already-loaded flag, avoiding a re-query — used by
     * {@see GetFeatureFlagsForTeamAction} when
     * resolving every flag for a team at once (e.g. for `/me`).
     */
    public function evaluate(FeatureFlag $flag, Team $team): bool
    {
        if (! $flag->enabled) {
            return false;
        }

        if (in_array($team->id, $flag->enabled_for_team_ids ?? [], true)) {
            return true;
        }

        return $this->bucketFor($flag->key, $team->id) < $flag->rollout_percentage;
    }

    /**
     * Stable 0-99 bucket for this team under this flag key.
     */
    private function bucketFor(string $key, int $teamId): int
    {
        return crc32("{$key}:{$teamId}") % 100;
    }
}
