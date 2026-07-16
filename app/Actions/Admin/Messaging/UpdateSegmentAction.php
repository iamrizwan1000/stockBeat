<?php

namespace App\Actions\Admin\Messaging;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\Segment;

class UpdateSegmentAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>|null  $filters
     */
    public function handle(AdminUser $admin, Segment $segment, string $name, ?array $filters): Segment
    {
        $before = ['name' => $segment->name, 'filters' => $segment->filters];

        $segment->update(['name' => $name, 'filters' => $filters]);

        $this->auditLog->handle($admin, 'segment.update', Segment::class, $segment->id, $before, [
            'name' => $segment->name,
            'filters' => $segment->filters,
        ]);

        return $segment;
    }
}
