<?php

namespace App\Actions\Notifications;

use App\Actions\Content\GetActiveContentBlocksAction;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Rule;

/**
 * The Free-tier "new order push" preset (Plan §4.4: "Free tier: preset
 * alerts only — new order push, daily digest"). A Free team can create zero
 * custom rules at all (`CreateRuleAction`'s `max_rules` gate is 0 for Free),
 * so there's never a `rules` row for `RuleEvaluationJob`'s `new_order`/
 * `high_value_order` dispatch (fired unconditionally by `IngestOrderAction`
 * on every genuinely new order) to actually match — without this action,
 * Free teams silently get no new-order push at all despite the plan
 * promising one. Always goes to the team owner, same convention as
 * `SendMorningDigestAction`'s Free-tier daily digest.
 *
 * Also implements the "locked-teaser" conversion mechanic (Plan §4.11):
 * when the order would also have tripped `high_value_order`, a Free team
 * gets a generic locked teaser ("upgrade for instant details") instead of
 * the real order number/total a paid team's own rule would surface. There's
 * no fixed platform-wide "high value" threshold anywhere else in the
 * codebase — `ConditionEvaluator`'s `total` condition is whatever a
 * Starter+ team's own rule configures — so this reuses the $200 figure
 * that's already `RuleController`'s own worked API-doc example for this
 * exact trigger, rather than inventing a new number.
 *
 * A no-op for every paid plan — those teams cover new-order/high-value
 * alerts entirely through their own real `rules` rows via the normal
 * `RuleEvaluationJob` path instead.
 */
class SendFreeTierNewOrderAlertAction
{
    private const HIGH_VALUE_THRESHOLD = 200.0;

    /**
     * Quoted verbatim from Plan §4.11. Used only when no
     * `paywall_locked_teaser_high_value_order` content block has been
     * seeded (Plan §8.7.3 lets an admin override this without an app
     * release — no such key exists yet as of this writing, so this is the
     * literal fallback every team currently sees).
     */
    private const FALLBACK_TEASER_BODY = 'High-value order 🔒 — upgrade for instant details & custom alerts.';

    private const TEASER_CONTENT_BLOCK_KEY = 'paywall_locked_teaser_high_value_order';

    public function __construct(
        private readonly SendOrderPushWithStormProtectionAction $sendOrderPush,
        private readonly GetActiveContentBlocksAction $getActiveContentBlocks,
    ) {}

    public function handle(Order $order): string
    {
        $team = $order->team;

        // Same plan-key resolution `ResolveEntitlementsAction` itself does
        // (`$subscription?->effectivePlanKey() ?? Plan::FREE`) — deliberately
        // not calling that action here, since its subsequent `Plan::
        // query()->firstOrFail()` pulls in the `plans`/`plan_limits` tables
        // (for `limits`, which this gate never uses) as a hard dependency of
        // every single order ingest, in every environment, even ones that
        // never seed plan data.
        $planKey = $team->subscription?->effectivePlanKey() ?? Plan::FREE;

        if ($planKey !== Plan::FREE) {
            return 'not_free_tier';
        }

        $owner = $team->owner;

        if ($owner === null) {
            return 'no_owner';
        }

        if ($this->isHighValue($order)) {
            $body = $this->getActiveContentBlocks->handle()[self::TEASER_CONTENT_BLOCK_KEY] ?? self::FALLBACK_TEASER_BODY;

            return $this->sendOrderPush->handle($owner, $order, 'High-value order', $body, connection: $order->connection, extraData: ['trigger' => Rule::TRIGGER_HIGH_VALUE_ORDER]);
        }

        $body = "{$order->order_number} · {$order->currency} ".number_format((float) $order->total, 2);

        return $this->sendOrderPush->handle($owner, $order, 'New order', $body, connection: $order->connection, extraData: ['trigger' => Rule::TRIGGER_NEW_ORDER]);
    }

    private function isHighValue(Order $order): bool
    {
        $total = (float) ($order->total_base_currency ?? $order->total);

        return $total >= self::HIGH_VALUE_THRESHOLD;
    }
}
