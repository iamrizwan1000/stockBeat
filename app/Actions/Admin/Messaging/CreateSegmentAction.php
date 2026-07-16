<?php

namespace App\Actions\Admin\Messaging;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\Segment;

class CreateSegmentAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>|null  $filters
     */
    public function handle(AdminUser $admin, string $name, ?array $filters): Segment
    {
        $segment = Segment::query()->create([
            'name' => $name,
            'filters' => $filters,
            'created_by' => $admin->id,
        ]);

        $this->auditLog->handle($admin, 'segment.create', Segment::class, $segment->id, null, [
            'name' => $segment->name,
            'filters' => $segment->filters,
        ]);

        return $segment;
    }
}
