<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Sets cost prices on several of the team's products in one call (Plan
 * §4.12 Phase B follow-up) — closes the "one at a time" gap
 * `products-api-reference.md` originally flagged for a seller with a large
 * catalog. Atomic ownership check up front: if any `id` in the batch
 * doesn't belong to the caller's team, the whole call 422s and nothing is
 * written, rather than silently applying the valid subset and hiding which
 * ones failed.
 */
class BulkUpdateCostPricesAction
{
    /**
     * @param  array<int, array{id: int, cost_price: float|null}>  $updates
     * @return Collection<int, Product>
     */
    public function handle(Team $team, array $updates): Collection
    {
        $ids = array_unique(array_column($updates, 'id'));

        $products = Product::query()->where('team_id', $team->id)->whereIn('id', $ids)->get()->keyBy('id');

        if ($products->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'updates' => ['One or more products do not belong to your team.'],
            ]);
        }

        foreach ($updates as $update) {
            $products[$update['id']]->update(['cost_price' => $update['cost_price'] ?? null]);
        }

        return $products->fresh();
    }
}
