<?php

namespace App\Actions\Admin\FeatureFlags;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\FeatureFlag;

class DeleteFeatureFlagAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, FeatureFlag $flag): void
    {
        $before = ['key' => $flag->key, 'name' => $flag->name];
        $flagId = $flag->id;

        $flag->delete();

        $this->auditLog->handle($admin, 'feature_flag.delete', FeatureFlag::class, $flagId, $before, null);
    }
}
