<?php

namespace App\Actions\Orders;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Models\Order;
use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Powers the unified order feed (Plan §4.2): filters, global search, and
 * the plan's `history_days` limit (§5) enforced server-side. Test orders
 * are excluded by default (§17.3).
 */
class ListOrdersAction
{
    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Order>
     */
    public function handle(Team $team, array $filters, ?TeamMember $actingMember = null): CursorPaginator
    {
        $historyDays = $this->resolveEntitlements->handle($team)['limits']['history_days'] ?? null;

        $query = Order::query()
            ->where('team_id', $team->id)
            ->where('is_test', false)
            ->with('items');

        // Plan §4.7: a member restricted to specific stores never sees
        // orders from the rest of the team's connections.
        if ($actingMember !== null && ! empty($actingMember->store_visibility)) {
            $query->whereIn('connection_id', $actingMember->store_visibility);
        }

        if ($historyDays !== null) {
            $query->where('placed_at', '>=', now()->subDays((int) $historyDays));
        }

        // Plan §4.2 "Snooze / remind-me-later": a snoozed order drops out
        // of the default feed until its snooze expires.
        if (empty($filters['include_snoozed'])) {
            $query->where(function ($q) {
                $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            });
        }

        if (! empty($filters['channel'])) {
            $query->where('platform', $filters['channel']);
        }

        if (! empty($filters['store'])) {
            $query->where('connection_id', $filters['store']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('placed_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('placed_at', '<=', $filters['date_to']);
        }

        if (isset($filters['value_min'])) {
            $query->where('total', '>=', $filters['value_min']);
        }

        if (isset($filters['value_max'])) {
            $query->where('total', '<=', $filters['value_max']);
        }

        if (! empty($filters['tag'])) {
            $query->whereJsonContains('tags', $filters['tag']);
        }

        if (! empty($filters['q'])) {
            $search = $filters['q'];

            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhereHas('items', function ($itemQuery) use ($search) {
                        $itemQuery->where('sku', 'like', "%{$search}%")
                            ->orWhere('title', 'like', "%{$search}%");
                    });
            });
        }

        return $query->orderByDesc('placed_at')->cursorPaginate(20);
    }
}
