<?php

namespace Database\Factories;

use App\Models\SupportMessage;
use App\Models\SupportThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportMessage>
 */
class SupportMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => SupportThread::factory(),
            'direction' => SupportMessage::DIRECTION_USER,
            'admin_id' => null,
            'body' => fake()->sentence(),
            'attachments' => null,
            'delivered_via' => null,
        ];
    }
}
