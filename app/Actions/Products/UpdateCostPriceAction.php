<?php

namespace App\Actions\Products;

use App\Models\Product;

/**
 * Sets a product's seller-entered cost basis (Plan §4.12 Phase B) — the
 * one field on `products` no platform API exposes, so it's always a
 * manual entry, never polled. `null` clears it (the product drops back out
 * of profit calculations rather than being treated as zero-cost, which
 * would fabricate 100% margin).
 */
class UpdateCostPriceAction
{
    public function handle(Product $product, ?float $costPrice): Product
    {
        $product->update(['cost_price' => $costPrice]);

        return $product;
    }
}
