<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'sku' => fake()->bothify('SKU-####'),
            'title' => fake()->words(3, true),
            'qty' => fake()->numberBetween(1, 3),
            'price' => fake()->randomFloat(2, 5, 100),
        ];
    }
}
