<?php

namespace App\Actions\Rules;

use App\Models\Order;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Support\Rules\ConditionEvaluator;
use Closure;

/**
 * Evaluates one rule against a trigger event (Plan §8.4) and, if it fires,
 * really dispatches its actions via `DispatchRuleActionsAction` — push,
 * email, and SMS (§15.2, via Twilio) are all real sends.
 */
class RuleEvaluationAction
{
    public function __construct(
        private readonly ConditionEvaluator $evaluator,
        private readonly DispatchRuleActionsAction $dispatchActions,
    ) {}

    /**
     * @param  array<string, mixed>  $context  Extra subject data for
     *                                         order-less triggers (see
     *                                         `DispatchRuleActionsAction`).
     */
    public function handle(Rule $rule, string $trigger, ?Order $order, array $context = []): ?RuleExecution
    {
        if (! $rule->enabled) {
            return null;
        }

        if (! $this->passesTimingGate($rule, $trigger, $order)) {
            return null;
        }

        if ($order !== null && ! $this->evaluator->evaluate($rule->conditions, $order)) {
            return null;
        }

        if ($this->alreadyFired($rule, $trigger, $order)) {
            return null;
        }

        if ($this->isWithinCooldown($rule, $trigger)) {
            return null;
        }

        if ($this->isWithinQuietHours($rule)) {
            return $this->log($rule, $trigger, $order, [['status' => 'skipped_quiet_hours']]);
        }

        $actionsResult = $this->dispatchActions->handle($rule, $rule->actions, $order, $context);

        return $this->log($rule, $trigger, $order, $actionsResult);
    }

    /**
     * Gates the two scheduler-driven triggers (Plan §4.4's "X hours")
     * against `controls.threshold_hours` (default 24h), and the two
     * "derived" spike triggers against `controls.spike_count`/
     * `spike_window_minutes` — every other trigger has no timing
     * precondition of its own, so it passes through.
     */
    private function passesTimingGate(Rule $rule, string $trigger, ?Order $order): bool
    {
        if ($order === null) {
            return true;
        }

        $hours = (int) ($rule->controls['threshold_hours'] ?? 24);

        return match ($trigger) {
            Rule::TRIGGER_UNFULFILLED_AFTER_X => $order->fulfillment_status !== Order::FULFILLMENT_FULFILLED
                && ! in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED], true)
                && $order->placed_at->lte(now()->subHours($hours)),
            Rule::TRIGGER_SHIP_BY_DEADLINE => $order->ship_by_at !== null
                && $order->fulfillment_status !== Order::FULFILLMENT_FULFILLED
                && $order->ship_by_at->isFuture()
                && $order->ship_by_at->lte(now()->addHours($hours)),
            Rule::TRIGGER_ORDER_SPIKE => $this->exceedsSpikeThreshold($rule, $order, fn ($minutes) => Order::query()
                ->where('team_id', $order->team_id)
                ->where('placed_at', '>=', now()->subMinutes($minutes))
                ->count()),
            Rule::TRIGGER_REFUND_SPIKE => $this->exceedsSpikeThreshold($rule, $order, fn ($minutes) => Order::query()
                ->where('team_id', $order->team_id)
                ->where('status', Order::STATUS_REFUNDED)
                ->where('updated_at', '>=', now()->subMinutes($minutes))
                ->count()),
            default => true,
        };
    }

    /**
     * @param  Closure(int): int  $countWithinWindow
     */
    private function exceedsSpikeThreshold(Rule $rule, Order $order, Closure $countWithinWindow): bool
    {
        $count = (int) ($rule->controls['spike_count'] ?? 10);
        $minutes = (int) ($rule->controls['spike_window_minutes'] ?? 30);

        return $countWithinWindow($minutes) >= $count;
    }

    /**
     * The (rule_id, order_id, trigger) hard dedup only makes sense when an
     * order gives each firing a distinct identity ("never fire twice for
     * the same order"). Order-less triggers (digest today; any future
     * derived trigger) have no such per-instance key, so an `exists()`
     * check here would block every firing after the first one forever —
     * their recurrence is governed by the scheduling command's own
     * due-check instead (see `SendRuleDigests`).
     */
    private function alreadyFired(Rule $rule, string $trigger, ?Order $order): bool
    {
        if ($order === null) {
            return false;
        }

        return RuleExecution::query()
            ->where('rule_id', $rule->id)
            ->where('trigger', $trigger)
            ->where('order_id', $order->id)
            ->exists();
    }

    private function isWithinCooldown(Rule $rule, string $trigger): bool
    {
        $cooldownMinutes = $rule->controls['cooldown_minutes'] ?? null;

        if ($cooldownMinutes === null) {
            return false;
        }

        return RuleExecution::query()
            ->where('rule_id', $rule->id)
            ->where('trigger', $trigger)
            ->where('fired_at', '>=', now()->subMinutes((int) $cooldownMinutes))
            ->exists();
    }

    private function isWithinQuietHours(Rule $rule): bool
    {
        $quietHours = $rule->controls['quiet_hours'] ?? null;

        if (empty($quietHours['start']) || empty($quietHours['end'])) {
            return false;
        }

        $timezone = $quietHours['timezone'] ?? $rule->team->owner->timezone ?? 'UTC';
        $now = now()->setTimezone($timezone)->format('H:i');
        [$start, $end] = [$quietHours['start'], $quietHours['end']];

        return $start <= $end
            ? $now >= $start && $now < $end
            : $now >= $start || $now < $end;
    }

    /**
     * @param  array<int, array<string, mixed>>  $actionsResult
     */
    private function log(Rule $rule, string $trigger, ?Order $order, array $actionsResult): RuleExecution
    {
        return RuleExecution::query()->create([
            'rule_id' => $rule->id,
            'order_id' => $order?->id,
            'trigger' => $trigger,
            'actions_result' => $actionsResult,
            'fired_at' => now(),
        ]);
    }
}
