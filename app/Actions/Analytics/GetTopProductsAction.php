<?php

namespace App\Actions\Analytics;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Top products by units/revenue within a range (Plan §4.6). Grouped by SKU
 * where present, falling back to product title for SKU-less items (Woo
 * line items can arrive with a blank SKU — matches the mapper's existing
 * "sku nullable" handling). Gated the same 3-way way as the summary
 * (`GetAnalyticsSummaryAction`) — Free is today-only, Starter adds 7d,
 * Pro/Premium get full history.
 */
class GetTopProductsAction
{
    private const RANGE_DAYS = ['today' => 1, '7d' => 7, '30d' => 30];

    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function handle(Team $team, string $range, int $limit = 10): Collection
    {
        $analyticsLevel = $this->resolveEntitlements->handle($team)['limits']['analytics_level'] ?? 'today';

        $allowedRanges = match ($analyticsLevel) {
            'full' => ['today', '7d', '30d'],
            '7d' => ['today', '7d'],
            default => ['today'],
        };

        if (! in_array($range, $allowedRanges, true)) {
            throw ValidationException::withMessages([
                'range' => 'Upgrade your plan for more analytics history.',
            ]);
        }

        $timezone = $team->owner->timezone ?? 'UTC';
        $days = self::RANGE_DAYS[$range];
        $start = Carbon::now($timezone)->startOfDay()->subDays($days - 1)->utc();
        $end = Carbon::now($timezone)->endOfDay()->utc();

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.team_id', $team->id)
            ->where('orders.is_test', false)
            ->whereBetween('orders.placed_at', [$start, $end])
            ->selectRaw('COALESCE(NULLIF(order_items.sku, ?), order_items.title) as product_key', [''])
            ->selectRaw('MAX(order_items.title) as title')
            ->selectRaw('MAX(order_items.sku) as sku')
            ->selectRaw('SUM(order_items.qty) as units')
            ->selectRaw('SUM(order_items.qty * order_items.price) as revenue')
            ->groupBy('product_key')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        /** @var Collection<int, array<string, mixed>> $products */
        $products = $rows->map(fn ($row): array => [
            'sku' => $row->sku ?: null,
            'title' => $row->title,
            'units' => (int) $row->units,
            'revenue' => round((float) $row->revenue, 2),
        ]);

        return $products;
    }
}
