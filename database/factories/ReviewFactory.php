<?php

namespace Database\Factories;

use App\Models\Review;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
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
            'product_title' => fake()->words(3, true),
            'rating' => fake()->numberBetween(1, 5),
            'reviewer_name' => fake()->name(),
            'content' => fake()->sentence(),
            'reviewed_at' => now(),
        ];
    }
}
