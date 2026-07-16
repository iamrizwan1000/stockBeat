<?php

namespace App\Actions\Analytics;

use App\Actions\Notifications\SendPushNotificationAction;
use App\Models\DailyStat;
use App\Models\Notification;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * "Yesterday: 23 orders, $1,840. Best seller: X." (Plan §4.6). A baseline
 * preset available on every plan, including Free (§4.4: "Free tier: preset
 * alerts only — new order push, daily digest") — unlike a custom Pro
 * `digest` rule (§4.4's `Rule::triggers()`), this doesn't require the team
 * to configure anything and always goes to the team owner. Reuses
 * `SendPushNotificationAction` so the owner's own notification
 * preferences/quiet hours (§4.8) still apply.
 */
class SendMorningDigestAction
{
    public function __construct(
        private readonly SendPushNotificationAction $sendPush,
    ) {}

    public function handle(Team $team, CarbonInterface $forDate): string
    {
        $totals = DailyStat::query()
            ->where('team_id', $team->id)
            ->whereBetween('date', [$forDate->copy()->startOfDay(), $forDate->copy()->endOfDay()])
            ->selectRaw('SUM(orders_count) as orders_count, SUM(revenue) as revenue')
            ->first();

        $ordersCount = (int) ($totals->orders_count ?? 0);
        $revenue = (float) ($totals->revenue ?? 0);

        if ($ordersCount === 0) {
            return 'no_orders';
        }

        $bestSeller = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.team_id', $team->id)
            ->where('orders.is_test', false)
            ->whereBetween('orders.placed_at', [
                $forDate->copy()->startOfDay(),
                $forDate->copy()->endOfDay(),
            ])
            ->selectRaw('order_items.title, SUM(order_items.qty * order_items.price) as revenue')
            ->groupBy('order_items.title')
            ->orderByDesc('revenue')
            ->value('title');

        $body = "Yesterday: {$ordersCount} orders, \${$this->formatMoney($revenue)}.";

        if ($bestSeller !== null) {
            $body .= " Best seller: {$bestSeller}.";
        }

        return $this->sendPush->handle(
            $team->owner,
            'Your daily summary',
            $body,
            [],
            Notification::TYPE_DIGEST,
        );
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2);
    }
}
