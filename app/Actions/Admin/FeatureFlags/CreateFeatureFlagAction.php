<?php

namespace App\Actions\Admin\FeatureFlags;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\FeatureFlag;

class CreateFeatureFlagAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, array $data): FeatureFlag
    {
        $flag = FeatureFlag::query()->create([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'enabled' => $data['enabled'] ?? false,
            'rollout_percentage' => $data['rollout_percentage'] ?? 0,
            'enabled_for_team_ids' => $data['enabled_for_team_ids'] ?? null,
            'updated_by' => $admin->id,
        ]);

        $this->auditLog->handle($admin, 'feature_flag.create', FeatureFlag::class, $flag->id, null, [
            'key' => $flag->key,
            'name' => $flag->name,
            'enabled' => $flag->enabled,
            'rollout_percentage' => $flag->rollout_percentage,
            'enabled_for_team_ids' => $flag->enabled_for_team_ids,
        ]);

        return $flag;
    }
}
