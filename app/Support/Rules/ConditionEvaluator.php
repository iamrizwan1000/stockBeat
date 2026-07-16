<?php

namespace App\Support\Rules;

use App\Models\Order;

/**
 * Evaluates a rule's condition tree (Plan §8.4: `{all: [...], any: [...]}`)
 * against a normalized order. A rule matches when every "all" condition is
 * true AND (any "any" condition is true, or "any" is empty).
 */
class ConditionEvaluator
{
    /**
     * @param  array{all?: array<int, array<string, mixed>>, any?: array<int, array<string, mixed>>}|null  $conditions
     */
    public function evaluate(?array $conditions, Order $order): bool
    {
        $all = $conditions['all'] ?? [];
        $any = $conditions['any'] ?? [];

        foreach ($all as $condition) {
            if (! $this->evaluateCondition($condition, $order)) {
                return false;
            }
        }

        if (empty($any)) {
            return true;
        }

        foreach ($any as $condition) {
            if ($this->evaluateCondition($condition, $order)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function evaluateCondition(array $condition, Order $order): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? null;
        $value = $condition['value'] ?? null;

        return match ($field) {
            'channel' => $this->compare($order->platform, $operator, $value),
            'store' => $this->compare($order->connection_id, $operator, $value),
            'total' => $this->compareNumeric((float) $order->total, $operator, $value),
            'sku' => $this->itemsContain($order, $value, 'sku'),
            'product' => $this->itemsContain($order, $value, 'title'),
            'quantity' => $this->compareNumeric((float) $order->items->sum('qty'), $operator, $value),
            'customer_country' => $this->compare($order->shipping_address['country'] ?? null, $operator, $value),
            'repeat_buyer' => $this->isRepeatBuyer($order) === (bool) $value,
            'shipping_method' => $this->compare($order->shipping_address['method'] ?? null, $operator, $value),
            'tag' => is_string($value) && in_array($value, $order->tags ?? [], true),
            default => false,
        };
    }

    private function compare(mixed $actual, ?string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'eq' => $actual == $expected,
            'neq' => $actual != $expected,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            default => false,
        };
    }

    private function compareNumeric(float $actual, ?string $operator, mixed $expected): bool
    {
        if ($operator === 'between' && is_array($expected) && count($expected) === 2) {
            return $actual >= (float) $expected[0] && $actual <= (float) $expected[1];
        }

        if (! is_numeric($expected)) {
            return false;
        }

        return match ($operator) {
            'gt' => $actual > $expected,
            'gte' => $actual >= $expected,
            'lt' => $actual < $expected,
            'lte' => $actual <= $expected,
            'eq' => $actual == $expected,
            default => false,
        };
    }

    private function itemsContain(Order $order, mixed $value, string $attribute): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        return $order->items->contains(
            fn ($item) => str_contains(strtolower((string) $item->{$attribute}), strtolower($value))
        );
    }

    private function isRepeatBuyer(Order $order): bool
    {
        if ($order->customer_email === null) {
            return false;
        }

        return Order::query()
            ->where('team_id', $order->team_id)
            ->where('customer_email', $order->customer_email)
            ->where('id', '!=', $order->id)
            ->exists();
    }
}
