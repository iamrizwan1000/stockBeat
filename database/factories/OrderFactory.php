<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
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
            'platform' => StoreConnection::PLATFORM_WOO,
            'external_id' => (string) fake()->unique()->numberBetween(1000, 999999),
            'order_number' => '#'.fake()->unique()->numberBetween(1000, 999999),
            'status' => Order::STATUS_NEW,
            'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
            'payment_status' => Order::PAYMENT_PAID,
            'currency' => 'USD',
            'total' => fake()->randomFloat(2, 10, 500),
            'total_base_currency' => fn (array $attrs) => $attrs['total'],
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'placed_at' => now(),
            'is_test' => false,
        ];
    }
}
