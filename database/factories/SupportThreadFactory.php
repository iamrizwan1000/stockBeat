<?php

namespace Database\Factories;

use App\Models\SupportThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportThread>
 */
class SupportThreadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => SupportThread::STATUS_OPEN,
            'assigned_admin_id' => null,
            'priority' => SupportThread::PRIORITY_NORMAL,
            'last_message_at' => now(),
            'csat' => null,
        ];
    }
}
