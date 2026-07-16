<?php

namespace App\Actions\Admin\Messaging;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\Announcement;
use Illuminate\Support\Carbon;

class UpdateAnnouncementAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, Announcement $announcement, array $data): Announcement
    {
        $before = [
            'title' => $announcement->title,
            'body' => $announcement->body,
            'audience' => $announcement->audience,
            'starts_at' => $announcement->starts_at?->toIso8601String(),
            'ends_at' => $announcement->ends_at?->toIso8601String(),
            'dismissible' => $announcement->dismissible,
        ];

        $announcement->update([
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => $data['audience'] ?? null,
            'starts_at' => isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null,
            'ends_at' => isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : null,
            'dismissible' => $data['dismissible'] ?? true,
        ]);

        $this->auditLog->handle($admin, 'announcement.update', Announcement::class, $announcement->id, $before, [
            'title' => $announcement->title,
            'body' => $announcement->body,
            'audience' => $announcement->audience,
            'starts_at' => $announcement->starts_at?->toIso8601String(),
            'ends_at' => $announcement->ends_at?->toIso8601String(),
            'dismissible' => $announcement->dismissible,
        ]);

        return $announcement;
    }
}
