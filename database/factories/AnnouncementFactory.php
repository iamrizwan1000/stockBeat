<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
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
            'audience' => null,
            'starts_at' => null,
            'ends_at' => null,
            'dismissible' => true,
            'created_by' => AdminUser::factory(),
        ];
    }
}
