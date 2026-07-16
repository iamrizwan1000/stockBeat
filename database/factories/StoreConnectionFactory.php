<?php

namespace Database\Factories;

use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreConnection>
 */
class StoreConnectionFactory extends Factory
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
            'platform' => StoreConnection::PLATFORM_WOO,
            'name' => fake()->domainName(),
            'status' => StoreConnection::STATUS_ACTIVE,
        ];
    }
}
