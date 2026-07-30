<?php

namespace Database\Factories;

use App\Models\ContactThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactThread>
 */
class ContactThreadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'subject' => fake()->sentence(4),
            'status' => ContactThread::STATUS_OPEN,
            'last_message_at' => now(),
        ];
    }
}
