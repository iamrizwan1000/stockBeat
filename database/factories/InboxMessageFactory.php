<?php

namespace Database\Factories;

use App\Models\InboxMessage;
use App\Models\InboxThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxMessage>
 */
class InboxMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => InboxThread::factory(),
            'direction' => InboxMessage::DIRECTION_OUT,
            'body' => fake()->sentence(),
            'sent_by' => null,
            'external_id' => null,
            'status' => InboxMessage::STATUS_SENT,
        ];
    }
}
