<?php

namespace Database\Factories;

use App\Models\DailyStat;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyStat>
 */
class DailyStatFactory extends Factory
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
            'date' => now()->toDateString(),
            'orders_count' => 0,
            'revenue' => 0,
            'revenue_base' => null,
            'aov' => 0,
            'refunds' => 0,
        ];
    }
}
