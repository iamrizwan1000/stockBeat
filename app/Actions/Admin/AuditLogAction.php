<?php

namespace App\Actions\Admin;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;

/**
 * Every admin write action lands here (Plan §8.7: "Every write action lands
 * in admin_audit_log").
 */
class AuditLogAction
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function handle(
        AdminUser $admin,
        string $action,
        ?string $targetType,
        ?int $targetId,
        ?array $before,
        ?array $after,
    ): AdminAuditLog {
        return AdminAuditLog::query()->create([
            'admin_id' => $admin->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before' => $before,
            'after' => $after,
            'at' => now(),
        ]);
    }
}
