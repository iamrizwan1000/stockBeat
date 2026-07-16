<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\CannedReply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CannedReply>
 */
class CannedReplyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'body' => fake()->paragraph(),
            'created_by' => AdminUser::factory(),
        ];
    }
}
