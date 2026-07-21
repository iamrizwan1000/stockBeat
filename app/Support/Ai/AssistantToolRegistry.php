<?php

namespace App\Support\Ai;

use App\Actions\Analytics\GetAnalyticsSummaryAction;
use App\Actions\Analytics\GetTopProductsAction;
use App\Actions\Billing\ResolveEntitlementsAction;
use App\Actions\Orders\ListOrdersAction;
use App\Models\AiUsageLedger;
use App\Models\Product;
use App\Models\SmsLedger;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The AI Assistant's only way to see a team's data (Plan §4.12) — every
 * tool wraps an existing, already-tested Action (or an equally
 * team-scoped query), so an answer is only ever built from real data the
 * app already trusts elsewhere. No tool executes a write; the assistant
 * cannot touch anything through this registry, only read it.
 *
 * Split into two groups because they're gated differently: App Help tools
 * (`appHelpDefinitions`) are available on every plan including Free and
 * never cost against the question quota; Data Copilot tools
 * (`dataDefinitions`) require `plan_limits.ai_enabled` and are what
 * `AskAssistantAction` debits `ai_usage_ledger` for. `AskAssistantAction`
 * decides which set(s) to offer a given request — this registry itself
 * doesn't gate anything, it just answers "what tools exist" and "run this
 * one."
 */
class AssistantToolRegistry
{
    private const RANGE_DAYS = ['today' => 1, '7d' => 7, '30d' => 30];

    private const RESTOCK_WINDOW_DAYS = 14;

    public function __construct(
        private readonly GetAnalyticsSummaryAction $analyticsSummary,
        private readonly GetTopProductsAction $topProducts,
        private readonly ListOrdersAction $listOrders,
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * @return array<int, string>
     */
    public function dataToolNames(): array
    {
        return ['get_sales_summary', 'get_top_products', 'list_orders', 'get_low_stock_products', 'get_profit_summary', 'get_restock_recommendations'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function appHelpDefinitions(): array
    {
        return [
            [
                'name' => 'get_connection_health',
                'description' => "The seller's connected stores and their sync status (active, needs reauthorization, disconnected, when they last synced). Use this for \"why isn't my store syncing\" / connection troubleshooting questions.",
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'get_account_status',
                'description' => "The seller's current plan, SMS credit balance, trial status, and AI question quota usage. Use this for billing/plan/subscription questions.",
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dataDefinitions(): array
    {
        return [
            [
                'name' => 'get_sales_summary',
                'description' => "Revenue, order count, and average order value for the seller's stores, with a per-channel breakdown and (on Pro/Premium) a period-over-period comparison and goal tracking. Use this for any question about sales, revenue, or how a period compares to another.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'range' => ['type' => 'string', 'enum' => ['today', '7d', '30d'], 'description' => 'The time window to summarize.'],
                    ],
                    'required' => ['range'],
                ],
            ],
            [
                'name' => 'get_top_products',
                'description' => 'Best-selling products by revenue within a range, with units sold. Use this for "best seller" / "top product" questions.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'range' => ['type' => 'string', 'enum' => ['today', '7d', '30d']],
                        'limit' => ['type' => 'integer', 'description' => 'Max products to return, default 10.'],
                    ],
                    'required' => ['range'],
                ],
            ],
            [
                'name' => 'list_orders',
                'description' => 'Search/list real orders — by status (unfulfilled, shipped, refunded, cancelled) or free-text (order number, customer name/email, item). Use this for questions about specific orders, unfulfilled/late orders, or refunds.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['new', 'unfulfilled', 'shipped', 'refunded', 'cancelled']],
                        'search' => ['type' => 'string', 'description' => 'Free-text search across order number, customer name/email, item title/SKU.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max orders to return, default 10, max 25.'],
                    ],
                ],
            ],
            [
                'name' => 'get_low_stock_products',
                'description' => "The seller's products with the lowest current stock quantity right now. Use this for a simple current-inventory question. For \"when will I run out\" / restock timing, use get_restock_recommendations instead.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max products to return, default 10.'],
                    ],
                ],
            ],
            [
                'name' => 'get_profit_summary',
                'description' => 'Estimated profit (revenue minus cost of goods) for a period. Only includes order line items whose product has a seller-entered cost price set — items without one are excluded, not assumed to be free. Use this for profit/margin questions.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'range' => ['type' => 'string', 'enum' => ['today', '7d', '30d']],
                    ],
                    'required' => ['range'],
                ],
            ],
            [
                'name' => 'get_restock_recommendations',
                'description' => 'Products likely to run out soon, estimated from real sales velocity over the last 14 days vs current stock. Only includes products that actually sold in that window — no recent sales means no reliable estimate, so those are excluded rather than guessed. Use this for "what should I restock" / "when will I run out" questions.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max products to return, default 10.'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments, Team $team): array
    {
        return match ($name) {
            'get_sales_summary' => $this->getSalesSummary($team, $arguments),
            'get_top_products' => $this->getTopProducts($team, $arguments),
            'list_orders' => $this->listOrders($team, $arguments),
            'get_low_stock_products' => $this->getLowStockProducts($team, $arguments),
            'get_profit_summary' => $this->getProfitSummary($team, $arguments),
            'get_restock_recommendations' => $this->getRestockRecommendations($team, $arguments),
            'get_account_status' => $this->getAccountStatus($team),
            'get_connection_health' => $this->getConnectionHealth($team),
            default => throw new InvalidArgumentException("Unknown assistant tool: {$name}"),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getSalesSummary(Team $team, array $arguments): array
    {
        return $this->analyticsSummary->handle($team, $arguments['range'] ?? 'today');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getTopProducts(Team $team, array $arguments): array
    {
        $limit = min((int) ($arguments['limit'] ?? 10), 25);

        return ['products' => $this->topProducts->handle($team, $arguments['range'] ?? 'today', $limit)->values()->all()];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listOrders(Team $team, array $arguments): array
    {
        $limit = min((int) ($arguments['limit'] ?? 10), 25);

        $filters = array_filter([
            'status' => $arguments['status'] ?? null,
            'q' => $arguments['search'] ?? null,
        ]);

        $orders = collect($this->listOrders->handle($team, $filters)->items())->take($limit);

        return [
            'orders' => $orders->map(fn ($order): array => [
                'order_number' => $order->order_number,
                'platform' => $order->platform,
                'status' => $order->status,
                'fulfillment_status' => $order->fulfillment_status,
                'total' => (float) $order->total,
                'currency' => $order->currency,
                'customer_name' => $order->customer_name,
                'placed_at' => $order->placed_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getLowStockProducts(Team $team, array $arguments): array
    {
        $limit = min((int) ($arguments['limit'] ?? 10), 25);

        $products = Product::query()
            ->where('team_id', $team->id)
            ->whereNotNull('stock_quantity')
            ->orderBy('stock_quantity')
            ->limit($limit)
            ->get(['title', 'sku', 'stock_quantity']);

        return ['products' => $products->toArray()];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getProfitSummary(Team $team, array $arguments): array
    {
        [$start, $end] = $this->resolveRange($team, $arguments['range'] ?? 'today');

        $row = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', function ($join) {
                $join->on('products.connection_id', '=', 'orders.connection_id')
                    ->on('products.sku', '=', 'order_items.sku');
            })
            ->where('orders.team_id', $team->id)
            ->where('orders.is_test', false)
            ->whereBetween('orders.placed_at', [$start, $end])
            ->selectRaw('SUM(order_items.qty * order_items.price) as total_revenue')
            ->selectRaw('SUM(CASE WHEN products.cost_price IS NOT NULL THEN order_items.qty * order_items.price ELSE 0 END) as revenue_with_cost_data')
            ->selectRaw('SUM(CASE WHEN products.cost_price IS NOT NULL THEN order_items.qty * products.cost_price ELSE 0 END) as total_cost')
            ->selectRaw('SUM(CASE WHEN products.cost_price IS NULL THEN order_items.qty ELSE 0 END) as units_missing_cost_price')
            ->first();

        $revenueWithCostData = round((float) ($row->revenue_with_cost_data ?? 0), 2);
        $totalCost = round((float) ($row->total_cost ?? 0), 2);
        $profit = round($revenueWithCostData - $totalCost, 2);

        return [
            'range' => $arguments['range'] ?? 'today',
            'total_revenue' => round((float) ($row->total_revenue ?? 0), 2),
            'revenue_with_cost_data' => $revenueWithCostData,
            'estimated_cost' => $totalCost,
            'estimated_profit' => $profit,
            'profit_margin_pct' => $revenueWithCostData > 0 ? round(($profit / $revenueWithCostData) * 100, 1) : null,
            'units_sold_missing_cost_price' => (int) ($row->units_missing_cost_price ?? 0),
            'note' => 'Only order items whose product has a seller-entered cost price are included. Items without one are excluded from the profit figure, never assumed to be zero-cost.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getRestockRecommendations(Team $team, array $arguments): array
    {
        $limit = min((int) ($arguments['limit'] ?? 10), 25);
        $since = now()->subDays(self::RESTOCK_WINDOW_DAYS);

        $unitsSoldBySku = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.team_id', $team->id)
            ->where('orders.is_test', false)
            ->where('orders.placed_at', '>=', $since)
            ->whereNotNull('order_items.sku')
            ->groupBy('order_items.sku')
            ->selectRaw('order_items.sku, SUM(order_items.qty) as units_sold')
            ->pluck('units_sold', 'sku');

        $recommendations = Product::query()
            ->where('team_id', $team->id)
            ->whereNotNull('stock_quantity')
            ->whereNotNull('sku')
            ->get(['title', 'sku', 'stock_quantity'])
            ->map(function (Product $product) use ($unitsSoldBySku): array {
                $unitsSold = (int) ($unitsSoldBySku[$product->sku] ?? 0);
                $perDay = $unitsSold / self::RESTOCK_WINDOW_DAYS;

                return [
                    'title' => $product->title,
                    'sku' => $product->sku,
                    'stock_quantity' => $product->stock_quantity,
                    'units_sold_last_14_days' => $unitsSold,
                    'estimated_days_until_stockout' => $perDay > 0 ? round($product->stock_quantity / $perDay, 1) : null,
                ];
            })
            ->filter(fn (array $r): bool => $r['estimated_days_until_stockout'] !== null)
            ->sortBy('estimated_days_until_stockout')
            ->take($limit)
            ->values();

        return [
            'recommendations' => $recommendations->all(),
            'note' => 'Estimated from real sales velocity over the last 14 days — only products that actually sold in that window get an estimate. No recent sales means no reliable estimate, so those products are excluded rather than guessed.',
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Team $team, string $range): array
    {
        $timezone = $team->owner->timezone ?? 'UTC';
        $days = self::RANGE_DAYS[$range] ?? 1;

        return [
            Carbon::now($timezone)->startOfDay()->subDays($days - 1)->utc(),
            Carbon::now($timezone)->endOfDay()->utc(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getAccountStatus(Team $team): array
    {
        $entitlements = $this->resolveEntitlements->handle($team);

        return [
            'plan' => $entitlements['plan'],
            'subscription_status' => $entitlements['subscription_status'],
            'trial_ends_at' => $entitlements['trial_ends_at'],
            'sms_balance' => SmsLedger::currentBalance($team->id),
            'ai_questions_used_this_month' => AiUsageLedger::questionsUsedThisMonth($team->id),
            'ai_questions_monthly_limit' => $entitlements['limits']['ai_questions_monthly'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getConnectionHealth(Team $team): array
    {
        $connections = StoreConnection::query()
            ->where('team_id', $team->id)
            ->get(['platform', 'name', 'status', 'webhook_status', 'last_sync_at']);

        return [
            'connections' => $connections->map(fn (StoreConnection $connection): array => [
                'platform' => $connection->platform,
                'name' => $connection->name,
                'status' => $connection->status,
                'webhook_status' => $connection->webhook_status,
                'last_sync_at' => $connection->last_sync_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
