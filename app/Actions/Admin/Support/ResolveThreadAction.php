<?php

namespace App\Actions\Admin\Support;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\SupportThread;

class ResolveThreadAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, SupportThread $thread): SupportThread
    {
        $before = ['status' => $thread->status];

        $thread->update(['status' => SupportThread::STATUS_RESOLVED, 'resolved_at' => now()]);

        $this->auditLog->handle($admin, 'support.resolve', SupportThread::class, $thread->id, $before, [
            'status' => $thread->status,
        ]);

        return $thread;
    }
}
