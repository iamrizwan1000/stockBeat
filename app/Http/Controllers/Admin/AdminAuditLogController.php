<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Team\ListAdminAuditLogAction;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminAuditLogController extends Controller
{
    public function index(Request $request, ListAdminAuditLogAction $action): Response
    {
        $filters = $request->only(['admin_id', 'action', 'target_type', 'from', 'to']);
        $entries = $action->handle($filters);

        return Inertia::render('admin/audit-log/index', [
            'filters' => $filters,
            'admins' => AdminUser::query()->orderBy('name')->get(['id', 'name']),
            'entries' => [
                'data' => collect($entries->items())->map(fn (AdminAuditLog $entry) => $this->summarize($entry))->all(),
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(AdminAuditLog $entry): array
    {
        return [
            'id' => $entry->id,
            'admin_name' => $entry->admin?->name,
            'action' => $entry->action,
            'target_type' => $entry->target_type,
            'target_id' => $entry->target_id,
            'before' => $entry->before,
            'after' => $entry->after,
            'at' => $entry->at,
        ];
    }
}
