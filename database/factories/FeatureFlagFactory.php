<?php

namespace Database\Factories;

use App\Models\FeatureFlag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureFlag>
 */
class FeatureFlagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => str_replace('-', '_', fake()->unique()->slug(2)),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'enabled' => true,
            'rollout_percentage' => 0,
            'enabled_for_team_ids' => null,
            'updated_by' => null,
        ];
    }
}
