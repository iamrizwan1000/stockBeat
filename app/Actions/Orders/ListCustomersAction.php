<?php

namespace App\Actions\Orders;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Models\Order;
use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Support\Collection;

/**
 * Powers the customer view (Plan §4.16) — a query-time aggregation over
 * `orders`, no new table. Same `history_days`/`store_visibility` gates and
 * test-order exclusion as `ListOrdersAction`, so a customer's stats never
 * imply access to more data than their plan/role actually grants.
 */
class ListCustomersAction
{
    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * @return Collection<int, object{customer_email: string, customer_name: ?string, order_count: int, total_spent: float, last_order_at: string}>
     */
    public function handle(Team $team, ?TeamMember $actingMember = null): Collection
    {
        $historyDays = $this->resolveEntitlements->handle($team)['limits']['history_days'] ?? null;

        $query = Order::query()
            ->where('team_id', $team->id)
            ->where('is_test', false)
            // A GDPR-erased customer's remaining orders have this nulled
            // (`ProcessShopifyGdprRequestAction`) — they can no longer be
            // grouped as one identity, so they're excluded entirely rather
            // than surfaced as a blank/anonymous row.
            ->whereNotNull('customer_email');

        if ($actingMember !== null && ! empty($actingMember->store_visibility)) {
            $query->whereIn('connection_id', $actingMember->store_visibility);
        }

        if ($historyDays !== null) {
            $query->where('placed_at', '>=', now()->subDays((int) $historyDays));
        }

        return $query
            ->selectRaw('customer_email, MAX(customer_name) as customer_name, COUNT(*) as order_count, SUM(total_base_currency) as total_spent, MAX(placed_at) as last_order_at')
            ->groupBy('customer_email')
            ->orderByDesc('last_order_at')
            ->get();
    }
}
