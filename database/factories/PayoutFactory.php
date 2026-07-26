<?php

namespace Database\Factories;

use App\Models\Payout;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
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
            'amount' => fake()->randomFloat(2, 10, 5000),
            'currency' => 'USD',
            'status' => Payout::STATUS_PAID,
            'arrival_date' => now(),
            'raw' => [],
        ];
    }
}
