<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'connection_id' => StoreConnection::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(1000, 999999),
            'sku' => fake()->bothify('SKU-####'),
            'title' => fake()->words(3, true),
            'stock_quantity' => fake()->numberBetween(0, 100),
        ];
    }
}
