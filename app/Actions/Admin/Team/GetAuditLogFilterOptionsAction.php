<?php

namespace App\Actions\Admin\Team;

use App\Models\AdminAuditLog;

/**
 * The set of `action`/`target_type` values actually present in the audit
 * log, so the filter UI can offer a real dropdown instead of a free-text
 * field nobody knows how to fill in.
 */
class GetAuditLogFilterOptionsAction
{
    /**
     * @return array{actions: array<int, string>, target_types: array<int, string>}
     */
    public function handle(): array
    {
        return [
            'actions' => AdminAuditLog::query()->distinct()->orderBy('action')->pluck('action')->all(),
            'target_types' => AdminAuditLog::query()->whereNotNull('target_type')->distinct()->orderBy('target_type')->pluck('target_type')->all(),
        ];
    }
}
