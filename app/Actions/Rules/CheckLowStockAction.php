<?php

namespace App\Actions\Rules;

use App\Models\Product;
use App\Models\Rule;

/**
 * Evaluates the `low_stock` trigger (Plan §4.4) for one polled product
 * against every enabled low_stock rule on its team. Order-less like
 * `digest` — there's no Order to run AND/OR conditions against, so this
 * bypasses `RuleEvaluationJob`/`ConditionEvaluator` entirely and calls
 * `RuleEvaluationAction` directly, same as `SendRuleDigests` does.
 *
 * Dedup is per-product via `low_stock_notified_at` rather than the rule's
 * generic cooldown: a rule cooldown is shared across every product on the
 * team, so it would wrongly suppress a second, different product going low
 * shortly after the first. `low_stock_notified_at` resets once the product
 * is restocked above every enabled rule's threshold, so a future re-drop
 * notifies again.
 */
class CheckLowStockAction
{
    public function __construct(
        private readonly RuleEvaluationAction $evaluation,
    ) {}

    public function handle(Product $product): void
    {
        if ($product->stock_quantity === null) {
            return;
        }

        $rules = Rule::query()
            ->where('team_id', $product->team_id)
            ->where('trigger', Rule::TRIGGER_LOW_STOCK)
            ->where('enabled', true)
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $isLowForAnyRule = false;
        $fired = false;

        foreach ($rules as $rule) {
            $threshold = (int) ($rule->controls['low_stock_threshold'] ?? 5);

            if ($product->stock_quantity > $threshold) {
                continue;
            }

            $isLowForAnyRule = true;

            if ($product->low_stock_notified_at === null) {
                $this->evaluation->handle($rule, Rule::TRIGGER_LOW_STOCK, null, [
                    'title' => $product->title,
                    'sku' => $product->sku,
                    'stock_quantity' => $product->stock_quantity,
                    'connection_id' => $product->connection_id,
                ]);
                $fired = true;
            }
        }

        if ($fired) {
            $product->update(['low_stock_notified_at' => now()]);
        } elseif (! $isLowForAnyRule && $product->low_stock_notified_at !== null) {
            $product->update(['low_stock_notified_at' => null]);
        }
    }
}
