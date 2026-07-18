<?php

namespace App\Actions\FeatureFlags;

use App\Models\FeatureFlag;
use App\Models\Team;

/**
 * Resolves every feature flag's effective value for a team in one shot —
 * composed into `/me` (Plan §8.7.3/§9) so the mobile app gets a
 * `feature_flags: {key: bool, ...}` map with zero app changes when admin
 * adjusts a flag's rollout.
 */
class GetFeatureFlagsForTeamAction
{
    public function __construct(
        private readonly IsFeatureEnabledForTeamAction $isFeatureEnabled,
    ) {}

    /**
     * @return array<string, bool>
     */
    public function handle(Team $team): array
    {
        return FeatureFlag::query()
            ->get()
            ->mapWithKeys(fn (FeatureFlag $flag) => [$flag->key => $this->isFeatureEnabled->evaluate($flag, $team)])
            ->all();
    }
}
