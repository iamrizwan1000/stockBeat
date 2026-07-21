<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductStockSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductStockSnapshot>
 */
class ProductStockSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'stock_quantity' => $this->faker->numberBetween(0, 100),
            'recorded_at' => now(),
        ];
    }
}
