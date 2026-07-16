<?php

namespace App\Actions\Admin\Messaging;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\Announcement;

class DeleteAnnouncementAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, Announcement $announcement): void
    {
        $before = ['title' => $announcement->title];
        $announcementId = $announcement->id;

        $announcement->delete();

        $this->auditLog->handle($admin, 'announcement.delete', Announcement::class, $announcementId, $before, null);
    }
}
