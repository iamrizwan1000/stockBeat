<?php

namespace App\Actions\Admin\FeatureFlags;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\FeatureFlag;

/**
 * The flag `key` is immutable after creation (it's the stable identifier
 * mobile-side `feature_flags` map entries are keyed by) — only the
 * human-facing fields plus enabled/rollout/allow-list are editable here.
 */
class UpdateFeatureFlagAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, FeatureFlag $flag, array $data): FeatureFlag
    {
        $before = [
            'name' => $flag->name,
            'description' => $flag->description,
            'enabled' => $flag->enabled,
            'rollout_percentage' => $flag->rollout_percentage,
            'enabled_for_team_ids' => $flag->enabled_for_team_ids,
        ];

        $flag->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'enabled' => $data['enabled'] ?? false,
            'rollout_percentage' => $data['rollout_percentage'] ?? 0,
            'enabled_for_team_ids' => $data['enabled_for_team_ids'] ?? null,
            'updated_by' => $admin->id,
        ]);

        $this->auditLog->handle($admin, 'feature_flag.update', FeatureFlag::class, $flag->id, $before, [
            'name' => $flag->name,
            'description' => $flag->description,
            'enabled' => $flag->enabled,
            'rollout_percentage' => $flag->rollout_percentage,
            'enabled_for_team_ids' => $flag->enabled_for_team_ids,
        ]);

        return $flag;
    }
}
