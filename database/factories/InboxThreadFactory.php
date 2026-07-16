<?php

namespace Database\Factories;

use App\Models\InboxThread;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxThread>
 */
class InboxThreadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'connection_id' => StoreConnection::factory(),
            'order_id' => null,
            'channel' => StoreConnection::PLATFORM_WOO,
            'external_thread_id' => null,
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'assigned_to' => null,
            'last_message_at' => now(),
        ];
    }
}
