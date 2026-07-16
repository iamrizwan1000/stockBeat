<?php

namespace App\Actions\Admin;

use App\Models\AdminUser;
use App\Models\AppConfig;

/**
 * Plan §8.7.7: "App config: minimum supported app version, maintenance-mode
 * banner, remote config JSON" — admin-editable, no app release required.
 */
class UpdateAppConfigAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, string $key, mixed $value): AppConfig
    {
        $config = AppConfig::query()->firstOrNew(['key' => $key]);
        $before = $config->exists ? ['value' => $config->value] : null;

        $config->value = $value;
        $config->updated_by = $admin->id;
        $config->save();

        $this->auditLog->handle($admin, 'app_config.update', AppConfig::class, $config->id, $before, [
            'key' => $key,
            'value' => $value,
        ]);

        return $config;
    }
}
