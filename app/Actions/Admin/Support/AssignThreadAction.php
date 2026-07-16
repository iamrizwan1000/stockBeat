<?php

namespace App\Actions\Admin\Support;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\SupportThread;

class AssignThreadAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, SupportThread $thread, ?AdminUser $assignee): SupportThread
    {
        $before = ['assigned_admin_id' => $thread->assigned_admin_id];

        $thread->update(['assigned_admin_id' => $assignee?->id]);

        $this->auditLog->handle($admin, 'support.assign', SupportThread::class, $thread->id, $before, [
            'assigned_admin_id' => $thread->assigned_admin_id,
        ]);

        return $thread;
    }
}
