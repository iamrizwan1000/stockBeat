<?php

namespace App\Actions\Rules;

use App\Models\Product;
use App\Models\ProductStockSnapshot;
use App\Models\Rule;
use Carbon\CarbonInterface;

/**
 * Evaluates the `stale_inventory` trigger (Plan §4.4, added 2026-07-27) —
 * "this product's stock hasn't changed in `controls.stale_days` (default
 * 30) days." Reuses `product_stock_snapshots` (Plan §4.12 Phase B), a table
 * that already existed and was already being written on every poll but had
 * no reader anywhere in the codebase until this action.
 *
 * **Honest platform scope:** only `PollWooProductsJob` writes snapshots
 * today (the only adapter with real product polling) — meaningless to call
 * for a product with no snapshot history, so it's a no-op until then, same
 * "inert, not fabricated" discipline as every other cross-platform gap in
 * this codebase.
 *
 * Dedup follows `CheckLowStockAction`'s exact shape: a per-product
 * `stale_stock_notified_at` timestamp, not the rule's generic cooldown (a
 * team-wide cooldown would wrongly suppress a second, different product
 * going stale shortly after the first). It resets once the stock actually
 * changes, so a future re-staleness period notifies again.
 */
class CheckStaleInventoryAction
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
            ->where('trigger', Rule::TRIGGER_STALE_INVENTORY)
            ->where('enabled', true)
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $lastChangedAt = $this->resolveLastChangedAt($product);

        if ($lastChangedAt === null) {
            // Not enough snapshot history yet to know whether this is stale.
            return;
        }

        $daysUnchanged = $lastChangedAt->diffInDays(now());

        $isStaleForAnyRule = false;
        $fired = false;

        foreach ($rules as $rule) {
            $thresholdDays = (int) ($rule->controls['stale_days'] ?? 30);

            if ($daysUnchanged < $thresholdDays) {
                continue;
            }

            $isStaleForAnyRule = true;

            if ($product->stale_stock_notified_at === null) {
                $this->evaluation->handle($rule, Rule::TRIGGER_STALE_INVENTORY, null, [
                    'title' => $product->title,
                    'sku' => $product->sku,
                    'stock_quantity' => $product->stock_quantity,
                    'days_unchanged' => $daysUnchanged,
                    'connection_id' => $product->connection_id,
                ]);
                $fired = true;
            }
        }

        if ($fired) {
            $product->update(['stale_stock_notified_at' => now()]);
        } elseif (! $isStaleForAnyRule && $product->stale_stock_notified_at !== null) {
            $product->update(['stale_stock_notified_at' => null]);
        }
    }

    /**
     * The most recent point the stock level actually differed from today's
     * value — everything after that has been constant. Falls back to the
     * earliest snapshot on record if the value has never changed at all
     * (i.e. "unchanged since we started tracking it").
     */
    private function resolveLastChangedAt(Product $product): ?CarbonInterface
    {
        $differing = ProductStockSnapshot::query()
            ->where('product_id', $product->id)
            ->where('stock_quantity', '!=', $product->stock_quantity)
            ->orderByDesc('recorded_at')
            ->first();

        if ($differing !== null) {
            return $differing->recorded_at;
        }

        $earliest = ProductStockSnapshot::query()
            ->where('product_id', $product->id)
            ->orderBy('recorded_at')
            ->first();

        return $earliest?->recorded_at;
    }
}
