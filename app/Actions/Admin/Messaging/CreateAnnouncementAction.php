<?php

namespace App\Actions\Admin\Messaging;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\Announcement;
use Illuminate\Support\Carbon;

class CreateAnnouncementAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, array $data): Announcement
    {
        $announcement = Announcement::query()->create([
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => $data['audience'] ?? null,
            'starts_at' => isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null,
            'ends_at' => isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : null,
            'dismissible' => $data['dismissible'] ?? true,
            'created_by' => $admin->id,
        ]);

        $this->auditLog->handle($admin, 'announcement.create', Announcement::class, $announcement->id, null, [
            'title' => $announcement->title,
        ]);

        return $announcement;
    }
}
