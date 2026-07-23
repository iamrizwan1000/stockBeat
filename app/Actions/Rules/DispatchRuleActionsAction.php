<?php

namespace App\Actions\Rules;

use App\Actions\Notifications\AutoTagAction;
use App\Actions\Notifications\NotifyMemberAction;
use App\Actions\Notifications\SendEmailNotificationAction;
use App\Actions\Notifications\SendOrderPushWithStormProtectionAction;
use App\Actions\Notifications\SendPushNotificationAction;
use App\Actions\Notifications\SendSmsNotificationAction;
use App\Models\DailyStat;
use App\Models\Order;
use App\Models\Rule;
use App\Models\StoreConnection;
use Illuminate\Support\Facades\DB;

/**
 * Actually dispatches a fired rule's actions (Plan §4.4) — this is what
 * replaces the earlier "logged_only" placeholder once the Notifications
 * module exists. Each action's real outcome (sent/quota_exceeded/failed/
 * etc.) is returned so `rule_executions.actions_result` stays honest.
 *
 * The single place per-store mute (`StoreConnection.notifications_muted`,
 * added 2026-07-23) is resolved and threaded into every channel action —
 * deliberately not duplicated inside each `Send*NotificationAction`'s
 * caller. `digest`/`ai_insight` fire with no resolvable store (team-wide by
 * nature) and so are never muted by this mechanism, which is correct, not
 * an oversight — there's no single store to mute a cross-store summary
 * against.
 *
 * Also the single place `data.trigger` (added 2026-07-24) is stamped onto
 * every push/email Notification Center row — "where did this alert come
 * from" (a store's platform, gated on `$connection` inside `SendPush.../
 * SendEmail...NotificationAction` themselves, or `ai_insight` for a
 * Proactive AI Insight, which has no store) — so the client can label a row
 * without guessing from its body text.
 */
class DispatchRuleActionsAction
{
    public function __construct(
        private readonly SendPushNotificationAction $sendPush,
        private readonly SendOrderPushWithStormProtectionAction $sendOrderPush,
        private readonly SendEmailNotificationAction $sendEmail,
        private readonly SendSmsNotificationAction $sendSms,
        private readonly NotifyMemberAction $notifyMember,
        private readonly AutoTagAction $autoTag,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @param  array<string, mixed>  $context  Extra subject data for order-less
     *                                         triggers that need more than the
     *                                         rule itself to describe what
     *                                         fired (e.g. low_stock's product,
     *                                         negative_review's review) — also
     *                                         where `connection_id` travels for
     *                                         those triggers, since there's no
     *                                         `$order` to derive it from.
     * @return array<int, array<string, mixed>>
     */
    public function handle(Rule $rule, array $actions, ?Order $order, array $context = []): array
    {
        $team = $rule->team;
        $creator = $rule->creator;
        $title = $rule->name;
        $body = $order !== null ? $this->describeOrder($order) : $this->describeRuleWithoutOrder($rule, $context);
        $connection = $this->resolveConnection($order, $context);
        $sourceData = ['trigger' => $rule->trigger];

        return collect($actions)
            ->map(function (array $action) use ($team, $creator, $title, $body, $order, $rule, $connection, $sourceData) {
                $type = $action['type'] ?? 'unknown';

                $status = match ($type) {
                    'push' => $order !== null
                        ? $this->sendOrderPush->handle($creator, $order, $title, $body, $rule->sound, connection: $connection, extraData: $sourceData)
                        : $this->sendPush->handle($creator, $title, $body, $sourceData, sound: $rule->sound, connection: $connection),
                    'email' => $this->sendEmail->handle($team, $creator, $title, $body, $connection, $sourceData),
                    'sms' => $this->sendSms->handle($team, $creator, $body, $connection),
                    'notify_member' => isset($action['user_id'])
                        ? $this->notifyMember->handle($team, (int) $action['user_id'], $title, $body, $rule->sound, $connection, $sourceData)
                        : 'missing_user_id',
                    'auto_tag' => $order !== null && isset($action['tag'])
                        ? $this->autoTag->handle($order, (string) $action['tag'])
                        : 'skipped_no_order',
                    default => 'unknown_action_type',
                };

                return ['type' => $type, 'status' => $status];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveConnection(?Order $order, array $context): ?StoreConnection
    {
        if ($order !== null) {
            return $order->connection;
        }

        if (isset($context['connection_id'])) {
            return StoreConnection::query()->find($context['connection_id']);
        }

        return null;
    }

    private function describeOrder(Order $order): string
    {
        return "{$order->order_number} · {$order->currency} ".number_format($order->total, 2);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function describeRuleWithoutOrder(Rule $rule, array $context): string
    {
        return match ($rule->trigger) {
            Rule::TRIGGER_DIGEST => $this->digestBody($rule),
            Rule::TRIGGER_LOW_STOCK => $this->lowStockBody($context),
            Rule::TRIGGER_NEGATIVE_REVIEW => $this->negativeReviewBody($context),
            Rule::TRIGGER_AI_INSIGHT => (string) ($context['insight'] ?? 'Your AI Assistant found something worth a look.'),
            default => 'Rule triggered.',
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function lowStockBody(array $context): string
    {
        if (! isset($context['title'], $context['stock_quantity'])) {
            return 'A product is running low on stock.';
        }

        $skuSuffix = isset($context['sku']) ? " (SKU {$context['sku']})" : '';

        return "{$context['title']}{$skuSuffix} is down to {$context['stock_quantity']} left.";
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function negativeReviewBody(array $context): string
    {
        if (! isset($context['rating'])) {
            return 'A negative review was received.';
        }

        $productSuffix = isset($context['product_title']) ? " on {$context['product_title']}" : '';
        $excerpt = isset($context['excerpt']) && $context['excerpt'] !== '' ? ": \"{$context['excerpt']}\"" : '.';

        return "{$context['rating']}★ review{$productSuffix}{$excerpt}";
    }

    /**
     * The free-tier morning digest (`SendMorningDigestAction`) and this
     * Pro custom-rule digest deliberately compute the same real stats
     * content rather than a generic placeholder, since the whole point of
     * firing it is the summary itself.
     */
    private function digestBody(Rule $rule): string
    {
        $frequency = $rule->controls['digest_frequency'] ?? 'daily';
        $end = now()->copy()->subDay()->endOfDay();
        $start = $frequency === 'weekly' ? $end->copy()->subDays(6)->startOfDay() : $end->copy()->startOfDay();

        $totals = DailyStat::query()
            ->where('team_id', $rule->team_id)
            ->whereBetween('date', [$start, $end])
            ->selectRaw('SUM(orders_count) as orders_count, SUM(revenue) as revenue')
            ->first();

        $ordersCount = (int) ($totals->orders_count ?? 0);
        $revenue = (float) ($totals->revenue ?? 0);

        if ($ordersCount === 0) {
            return $frequency === 'weekly' ? 'No orders in the last 7 days.' : 'No orders yesterday.';
        }

        $bestSeller = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.team_id', $rule->team_id)
            ->where('orders.is_test', false)
            ->whereBetween('orders.placed_at', [$start, $end])
            ->selectRaw('order_items.title, SUM(order_items.qty * order_items.price) as revenue')
            ->groupBy('order_items.title')
            ->orderByDesc('revenue')
            ->value('title');

        $label = $frequency === 'weekly' ? 'Last 7 days' : 'Yesterday';
        $body = "{$label}: {$ordersCount} orders, \$".number_format($revenue, 2).'.';

        if ($bestSeller !== null) {
            $body .= " Best seller: {$bestSeller}.";
        }

        return $body;
    }
}
