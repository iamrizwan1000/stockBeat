<?php

namespace Database\Factories;

use App\Models\ContentBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentBlock>
 */
class ContentBlockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => str_replace('-', '_', fake()->unique()->slug(3)),
            'title' => fake()->words(3, true),
            'body' => fake()->sentence(),
            'locale' => 'en',
            'active' => true,
        ];
    }
}
