<?php

namespace App\Actions\Rules;

use App\Models\Product;
use App\Models\ProductStockSnapshot;
use App\Models\Rule;

/**
 * Evaluates the `back_in_stock` trigger (Plan §4.24, added 2026-07-28) —
 * fires once when a product's stock goes from 0 back to a positive
 * quantity. Reuses `product_stock_snapshots`, the same time series
 * `CheckStaleInventoryAction` reads.
 *
 * **Honest platform scope:** only `PollWooProductsJob` writes snapshots
 * today, so this is a no-op (not a false negative) for any product with no
 * snapshot history — same "inert, not fabricated" discipline as
 * `stale_inventory`.
 *
 * No configurable threshold, unlike `low_stock`/`stale_inventory` — the
 * condition is binary (was it actually at zero before this?), so there's
 * nothing for a seller to tune.
 *
 * Dedup via `back_in_stock_notified_at`: set once fired, reset back to
 * `null` the moment stock drops to zero again so the next replenishment
 * fires again. Unlike `low_stock`/`stale_inventory`'s reset (which fires on
 * every poll where the condition no longer holds), the reset here happens
 * on the *out-of-stock* poll, not the *in-stock* one — the two are
 * opposite triggers of the same underlying signal.
 */
class CheckBackInStockAction
{
    public function __construct(
        private readonly RuleEvaluationAction $evaluation,
    ) {}

    public function handle(Product $product): void
    {
        if ($product->stock_quantity === null) {
            return;
        }

        if ($product->stock_quantity <= 0) {
            if ($product->back_in_stock_notified_at !== null) {
                $product->update(['back_in_stock_notified_at' => null]);
            }

            return;
        }

        if ($product->back_in_stock_notified_at !== null) {
            // Already notified for this in-stock streak.
            return;
        }

        $rules = Rule::query()
            ->where('team_id', $product->team_id)
            ->where('trigger', Rule::TRIGGER_BACK_IN_STOCK)
            ->where('enabled', true)
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $previous = ProductStockSnapshot::query()
            ->where('product_id', $product->id)
            ->where('stock_quantity', '!=', $product->stock_quantity)
            ->orderByDesc('recorded_at')
            ->first();

        if ($previous === null || $previous->stock_quantity > 0) {
            // No history, or the last real change wasn't from zero — not a
            // genuine restock event.
            return;
        }

        foreach ($rules as $rule) {
            $this->evaluation->handle($rule, Rule::TRIGGER_BACK_IN_STOCK, null, [
                'title' => $product->title,
                'sku' => $product->sku,
                'stock_quantity' => $product->stock_quantity,
                'connection_id' => $product->connection_id,
            ]);
        }

        $product->update(['back_in_stock_notified_at' => now()]);
    }
}
