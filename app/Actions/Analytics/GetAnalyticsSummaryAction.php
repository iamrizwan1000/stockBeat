<?php

namespace App\Actions\Analytics;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Models\DailyStat;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Business overview / analytics-lite (Plan §4.6): today/7-day/30-day
 * revenue + order count + AOV, per-channel breakdown with period-over-
 * period comparison, and goal tracking against the team's best calendar
 * month. "Today" is always computed live from `orders` — it can never come
 * from `daily_stats`, which is only ever written for a *finished* day
 * (§9's "pre-aggregated" table, filled by the nightly
 * `analytics:aggregate-daily` job). Free plans are gated to `range=today`
 * only via `plan_limits.analytics_level` (§5.1 "Full analytics" is a Pro
 * perk).
 */
class GetAnalyticsSummaryAction
{
    private const RANGE_DAYS = ['today' => 1, '7d' => 7, '30d' => 30];

    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Team $team, string $range): array
    {
        $analyticsLevel = $this->resolveEntitlements->handle($team)['limits']['analytics_level'] ?? 'today';
        $isFull = $analyticsLevel === 'full';

        // Starter's '7d' level sits between Free's 'today'-only and Pro/
        // Premium's 'full' (30d + comparison + goal tracking).
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
        $today = Carbon::now($timezone)->startOfDay();
        $days = self::RANGE_DAYS[$range];

        $periodStart = $today->copy()->subDays($days - 1);
        $totals = $this->totalsForPeriod($team, $periodStart, $today, $timezone);
        $byChannel = $this->byChannelForPeriod($team, $periodStart, $today, $timezone);

        $result = [
            'range' => $range,
            'total' => $totals,
            'by_channel' => $byChannel,
        ];

        if ($isFull) {
            $previousStart = $periodStart->copy()->subDays($days);
            $previousEnd = $periodStart->copy()->subDay();
            $previousTotals = $this->totalsForPeriod($team, $previousStart, $previousEnd, $timezone, includeToday: false);

            $result['comparison'] = [
                'previous_period_revenue' => $previousTotals['revenue'],
                'change_pct' => $this->percentChange($previousTotals['revenue'], $totals['revenue']),
            ];
            $result['goal'] = $this->goalTracking($team, $timezone);
        }

        return $result;
    }

    /**
     * @return array{revenue: float, revenue_base: float|null, orders_count: int, aov: float}
     */
    private function totalsForPeriod(Team $team, Carbon $start, Carbon $end, string $timezone, bool $includeToday = true): array
    {
        $today = Carbon::now($timezone)->startOfDay();
        $historicalEnd = $end->isSameDay($today) ? $today->copy()->subDay() : $end;

        $historical = $historicalEnd->lt($start)
            ? ['orders_count' => 0, 'revenue' => 0.0, 'revenue_base' => null]
            : $this->historicalTotals($team, $start, $historicalEnd);

        $ordersCount = $historical['orders_count'];
        $revenue = $historical['revenue'];
        $revenueBaseParts = $historical['revenue_base'] === null ? [] : [$historical['revenue_base']];

        if ($includeToday && $end->isSameDay($today) && $today->gte($start)) {
            $liveToday = $this->liveTodayTotals($team, $timezone);
            $ordersCount += $liveToday['orders_count'];
            $revenue += $liveToday['revenue'];

            if ($liveToday['revenue_base'] !== null) {
                $revenueBaseParts[] = $liveToday['revenue_base'];
            }
        }

        return [
            'revenue' => round($revenue, 2),
            'revenue_base' => $revenueBaseParts === [] ? null : round(array_sum($revenueBaseParts), 2),
            'orders_count' => $ordersCount,
            'aov' => $ordersCount > 0 ? round($revenue / $ordersCount, 2) : 0.0,
        ];
    }

    /**
     * @return array{orders_count: int, revenue: float, revenue_base: float|null}
     */
    private function historicalTotals(Team $team, Carbon $start, Carbon $end): array
    {
        $row = DailyStat::query()
            ->where('team_id', $team->id)
            ->whereBetween('date', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('SUM(orders_count) as orders_count, SUM(revenue) as revenue, SUM(revenue_base) as revenue_base')
            ->first();

        return [
            'orders_count' => (int) ($row->orders_count ?? 0),
            'revenue' => (float) ($row->revenue ?? 0),
            'revenue_base' => $row->revenue_base !== null ? (float) $row->revenue_base : null,
        ];
    }

    /**
     * @return array{orders_count: int, revenue: float, revenue_base: float|null}
     */
    private function liveTodayTotals(Team $team, string $timezone): array
    {
        $start = Carbon::now($timezone)->startOfDay()->utc();
        $end = Carbon::now($timezone)->endOfDay()->utc();

        $orders = Order::query()
            ->where('team_id', $team->id)
            ->where('is_test', false)
            ->whereBetween('placed_at', [$start, $end])
            ->get();

        $resolvedBase = $orders->whereNotNull('total_base_currency');

        return [
            'orders_count' => $orders->count(),
            'revenue' => (float) $orders->sum('total'),
            'revenue_base' => $resolvedBase->isEmpty() ? null : (float) $resolvedBase->sum('total_base_currency'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function byChannelForPeriod(Team $team, Carbon $start, Carbon $end, string $timezone): array
    {
        return StoreConnection::query()
            ->where('team_id', $team->id)
            ->get()
            ->map(function (StoreConnection $connection) use ($start, $end, $timezone) {
                $totals = $this->totalsForConnection($connection, $start, $end, $timezone);

                return [
                    'connection_id' => $connection->id,
                    'platform' => $connection->platform,
                    'name' => $connection->name,
                    ...$totals,
                ];
            })
            ->all();
    }

    /**
     * @return array{revenue: float, revenue_base: float|null, orders_count: int, aov: float}
     */
    private function totalsForConnection(StoreConnection $connection, Carbon $start, Carbon $end, string $timezone): array
    {
        $today = Carbon::now($timezone)->startOfDay();
        $historicalEnd = $end->isSameDay($today) ? $today->copy()->subDay() : $end;

        $ordersCount = 0;
        $revenue = 0.0;
        $revenueBaseParts = [];

        if ($historicalEnd->gte($start)) {
            $row = DailyStat::query()
                ->where('connection_id', $connection->id)
                ->whereBetween('date', [$start->copy()->startOfDay(), $historicalEnd->copy()->endOfDay()])
                ->selectRaw('SUM(orders_count) as orders_count, SUM(revenue) as revenue, SUM(revenue_base) as revenue_base')
                ->first();

            $ordersCount += (int) ($row->orders_count ?? 0);
            $revenue += (float) ($row->revenue ?? 0);

            if ($row->revenue_base !== null) {
                $revenueBaseParts[] = (float) $row->revenue_base;
            }
        }

        if ($end->isSameDay($today) && $today->gte($start)) {
            $dayStart = $today->copy()->utc();
            $dayEnd = Carbon::now($timezone)->endOfDay()->utc();

            $orders = Order::query()
                ->where('connection_id', $connection->id)
                ->where('is_test', false)
                ->whereBetween('placed_at', [$dayStart, $dayEnd])
                ->get();

            $ordersCount += $orders->count();
            $revenue += (float) $orders->sum('total');
            $resolvedBase = $orders->whereNotNull('total_base_currency');

            if ($resolvedBase->isNotEmpty()) {
                $revenueBaseParts[] = (float) $resolvedBase->sum('total_base_currency');
            }
        }

        return [
            'revenue' => round($revenue, 2),
            'revenue_base' => $revenueBaseParts === [] ? null : round(array_sum($revenueBaseParts), 2),
            'orders_count' => $ordersCount,
            'aov' => $ordersCount > 0 ? round($revenue / $ordersCount, 2) : 0.0,
        ];
    }

    private function percentChange(float $previous, float $current): ?float
    {
        if ($previous <= 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @return array{current_month_revenue: float, best_month_revenue: float, pct_of_best_month: float|null}
     */
    private function goalTracking(Team $team, string $timezone): array
    {
        $now = Carbon::now($timezone);
        $monthStart = $now->copy()->startOfMonth();

        $currentMonthHistorical = DailyStat::query()
            ->where('team_id', $team->id)
            ->whereBetween('date', [$monthStart->copy()->startOfDay(), $now->copy()->subDay()->endOfDay()])
            ->sum('revenue');

        $currentMonthRevenue = (float) $currentMonthHistorical + $this->liveTodayTotals($team, $timezone)['revenue'];

        // Grouped in PHP rather than SQL (DATE_FORMAT/strftime differ
        // between MariaDB and SQLite) — daily_stats stays small enough
        // per team that this is cheap.
        $bestMonthRevenue = DailyStat::query()
            ->where('team_id', $team->id)
            ->get(['date', 'revenue'])
            ->groupBy(fn (DailyStat $stat) => $stat->date->format('Y-m'))
            ->map(fn ($rows) => (float) $rows->sum('revenue'))
            ->max() ?? 0.0;

        $bestMonthRevenue = max($bestMonthRevenue, $currentMonthRevenue);

        return [
            'current_month_revenue' => round($currentMonthRevenue, 2),
            'best_month_revenue' => round($bestMonthRevenue, 2),
            'pct_of_best_month' => $bestMonthRevenue > 0 ? round(($currentMonthRevenue / $bestMonthRevenue) * 100, 1) : null,
        ];
    }
}
