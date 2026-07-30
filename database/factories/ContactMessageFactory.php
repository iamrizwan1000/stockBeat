<?php

namespace Database\Factories;

use App\Models\ContactMessage;
use App\Models\ContactThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactMessage>
 */
class ContactMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => ContactThread::factory(),
            'direction' => ContactMessage::DIRECTION_GUEST,
            'body' => fake()->paragraph(),
        ];
    }
}
