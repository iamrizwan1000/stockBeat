<?php

namespace App\Actions\Analytics;

use App\Models\DailyStat;
use App\Models\Order;
use App\Models\StoreConnection;
use Carbon\CarbonInterface;

/**
 * Rolls up one connection's orders for one calendar day into `daily_stats`
 * (Plan §9) — historical (7-day/30-day) analytics reads from this instead
 * of re-scanning `orders`. Test orders are excluded throughout (§17.3).
 * The day boundary follows the team owner's timezone (falls back to UTC),
 * matching the existing quiet-hours convention, since "yesterday" is a
 * user-facing concept.
 */
class AggregateDailyStatsAction
{
    public function handle(StoreConnection $connection, CarbonInterface $date): DailyStat
    {
        $timezone = $connection->team->owner->timezone ?? 'UTC';
        $start = $date->copy()->setTimezone($timezone)->startOfDay()->utc();
        $end = $date->copy()->setTimezone($timezone)->endOfDay()->utc();

        $orders = Order::query()
            ->where('connection_id', $connection->id)
            ->where('is_test', false)
            ->whereBetween('placed_at', [$start, $end])
            ->get();

        $ordersCount = $orders->count();
        $revenue = (float) $orders->sum('total');
        $resolvedBaseOrders = $orders->whereNotNull('total_base_currency');
        $revenueBase = $resolvedBaseOrders->isEmpty() ? null : (float) $resolvedBaseOrders->sum('total_base_currency');
        $refunds = $orders->whereIn('payment_status', [Order::PAYMENT_REFUNDED, Order::PAYMENT_PARTIALLY_REFUNDED])->count();

        return DailyStat::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'date' => $date->copy()->startOfDay()],
            [
                'team_id' => $connection->team_id,
                'orders_count' => $ordersCount,
                'revenue' => $revenue,
                'revenue_base' => $revenueBase,
                'aov' => $ordersCount > 0 ? round($revenue / $ordersCount, 2) : 0,
                'refunds' => $refunds,
            ],
        );
    }
}
