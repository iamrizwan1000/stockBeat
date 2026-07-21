<?php

namespace Database\Factories;

use App\Models\AiTopupPack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiTopupPack>
 */
class AiTopupPackFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'ai_'.fake()->unique()->numberBetween(50, 9999),
            'name' => fake()->words(2, true),
            'ai_questions' => 50,
            'price_usd' => 4.99,
            'active' => true,
            'sort_order' => 0,
        ];
    }
}
