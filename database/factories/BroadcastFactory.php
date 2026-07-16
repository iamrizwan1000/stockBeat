<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\Broadcast;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Broadcast>
 */
class BroadcastFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'audience_type' => Broadcast::AUDIENCE_ALL,
            'segment_id' => null,
            'user_id' => null,
            'channels' => [Broadcast::CHANNEL_EMAIL],
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'template_vars' => null,
            'status' => Broadcast::STATUS_DRAFT,
            'scheduled_at' => null,
            'sent_at' => null,
            'stats' => null,
            'created_by' => AdminUser::factory(),
            'approved_by' => null,
        ];
    }
}
