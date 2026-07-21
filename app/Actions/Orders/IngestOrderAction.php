<?php

namespace App\Actions\Orders;

use App\Actions\Billing\ConvertToBaseCurrencyAction;
use App\Jobs\RuleEvaluationJob;
use App\Jobs\SendFreeTierNewOrderAlertJob;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Rule;
use App\Models\StoreConnection;
use App\Support\Orders\NormalizedOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Normalizes a platform order into the unified `orders` model (Plan §8.2,
 * §15.3 "Order normalizer"). Idempotent on (connection_id, external_id) —
 * duplicate webhook deliveries and repeated polls converge to the same row
 * (§17.3). Only the first ingest writes a `created` order_events entry;
 * later changes write `updated` — this is what stops a downstream rules
 * engine from ever re-firing "new order" on an edit (§17.3). A genuinely
 * new order also dispatches the `new_order`/`high_value_order`/
 * `order_spike` rule evaluation (Plan §8.4) once the transaction commits,
 * plus the independent Free-tier "new order push" preset (Plan §4.4/§4.11,
 * `SendFreeTierNewOrderAlertJob`) — a no-op for every paid plan.
 */
class IngestOrderAction
{
    public function __construct(
        private readonly ConvertToBaseCurrencyAction $convertToBaseCurrency,
    ) {}

    public function handle(StoreConnection $connection, NormalizedOrder $normalized): Order
    {
        return DB::transaction(function () use ($connection, $normalized) {
            $existing = Order::query()
                ->where('connection_id', $connection->id)
                ->where('external_id', $normalized->externalId)
                ->first();

            $order = Order::query()->updateOrCreate(
                ['connection_id' => $connection->id, 'external_id' => $normalized->externalId],
                [
                    'team_id' => $connection->team_id,
                    'platform' => $connection->platform,
                    'order_number' => $normalized->orderNumber,
                    'status' => $normalized->status,
                    'fulfillment_status' => $normalized->fulfillmentStatus,
                    'payment_status' => $normalized->paymentStatus,
                    'currency' => $normalized->currency,
                    'total' => $normalized->total,
                    'discount_amount' => $normalized->discountAmount,
                    'tax' => $normalized->tax,
                    'total_base_currency' => $this->resolveBaseCurrencyTotal($connection, $normalized),
                    'customer_name' => $normalized->customerName,
                    'customer_email' => $normalized->customerEmail,
                    'buyer_username' => $normalized->buyerUsername,
                    'shipping_address' => $normalized->shippingAddress,
                    'shipping_country' => $normalized->shippingAddress['country'] ?? null,
                    'placed_at' => $normalized->placedAt,
                    'ship_by_at' => $normalized->shipByAt,
                    'check_at' => $this->resolveCheckAt($normalized),
                    'tags' => $normalized->tags,
                    'raw' => $normalized->raw,
                    'is_test' => $normalized->isTest,
                ],
            );

            $order->items()->delete();

            foreach ($normalized->items as $item) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'external_id' => $item->externalId,
                    'legacy_item_id' => $item->legacyItemId,
                    'sku' => $item->sku,
                    'title' => $item->title,
                    'image_url' => $item->imageUrl,
                    'qty' => $item->qty,
                    'price' => $item->price,
                ]);
            }

            OrderEvent::query()->create([
                'order_id' => $order->id,
                'type' => $existing === null ? OrderEvent::TYPE_CREATED : OrderEvent::TYPE_UPDATED,
                'occurred_at' => now(),
            ]);

            if ($existing === null) {
                RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_NEW_ORDER)->afterCommit();
                RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_HIGH_VALUE_ORDER)->afterCommit();
                RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_ORDER_SPIKE)->afterCommit();
                SendFreeTierNewOrderAlertJob::dispatch($order->id)->afterCommit();
            } else {
                $this->dispatchStatusTransitionTriggers($order, $existing);
            }

            return $order;
        });
    }

    /**
     * The scheduler-driven triggers (§8.4: "scans orders with due check_at
     * timestamps") only care about orders still awaiting fulfillment —
     * clearing check_at once an order reaches a terminal state prunes it
     * from that scan permanently rather than re-checking it forever.
     */
    private function resolveCheckAt(NormalizedOrder $normalized): ?CarbonInterface
    {
        $terminal = [Order::STATUS_SHIPPED, Order::STATUS_CANCELLED, Order::STATUS_REFUNDED];

        return in_array($normalized->status, $terminal, true) ? null : $normalized->placedAt;
    }

    /**
     * Fires the webhook/poll-sourced triggers (§4.4: refund_requested,
     * order_cancelled, payment_failed) on a genuine status transition —
     * only for ingest, since these represent something happening *to* the
     * order from the platform side, not the merchant's own quick actions
     * (which already know they just cancelled/refunded it themselves).
     */
    private function dispatchStatusTransitionTriggers(Order $order, Order $existing): void
    {
        if ($order->status === Order::STATUS_CANCELLED && $existing->status !== Order::STATUS_CANCELLED) {
            RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_ORDER_CANCELLED)->afterCommit();
        }

        if ($order->status === Order::STATUS_REFUNDED && $existing->status !== Order::STATUS_REFUNDED) {
            RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_REFUND_REQUESTED)->afterCommit();
            RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_REFUND_SPIKE)->afterCommit();
        }

        if ($order->payment_status === Order::PAYMENT_FAILED && $existing->payment_status !== Order::PAYMENT_FAILED) {
            RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_PAYMENT_FAILED)->afterCommit();
        }
    }

    /**
     * The team's reporting currency isn't its own column (§9 only defines
     * it on `users`) — the owner's `base_currency` stands in for it.
     * Resolved via `fx_rates` (§4.6/§9) using the rate on or before the
     * order's own placed-at date; still `null` (never fabricated) if no
     * rate has synced for that pair yet.
     */
    private function resolveBaseCurrencyTotal(StoreConnection $connection, NormalizedOrder $normalized): ?float
    {
        $baseCurrency = $connection->team->owner->base_currency;

        return $this->convertToBaseCurrency->handle(
            $normalized->total,
            $normalized->currency,
            $baseCurrency,
            $normalized->placedAt,
        );
    }
}
