<?php

namespace App\Actions\Admin\Messaging;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\Segment;

class DeleteSegmentAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, Segment $segment): void
    {
        $before = ['name' => $segment->name, 'filters' => $segment->filters];
        $segmentId = $segment->id;

        $segment->delete();

        $this->auditLog->handle($admin, 'segment.delete', Segment::class, $segmentId, $before, null);
    }
}
