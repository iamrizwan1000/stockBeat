<?php

namespace App\Actions\Admin\Team;

use App\Models\AdminAuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Plan §8.7.8: "full audit log (who/what/before/after/when) with search."
 */
class ListAdminAuditLogAction
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, AdminAuditLog>
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = AdminAuditLog::query()->with('admin');

        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(function ($query) use ($q) {
                $query->where('action', 'like', "%{$q}%")
                    ->orWhereHas('admin', fn ($adminQuery) => $adminQuery->where('name', 'like', "%{$q}%"));
            });
        }

        if (! empty($filters['admin_id'])) {
            $query->where('admin_id', $filters['admin_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['target_type'])) {
            $query->where('target_type', $filters['target_type']);
        }

        if (! empty($filters['from'])) {
            $query->where('at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('at', '<=', $filters['to']);
        }

        return $query->orderByDesc('at')->paginate(50)->withQueryString();
    }
}
