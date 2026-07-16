<?php

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
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
            'push_enabled' => true,
            'email_enabled' => true,
            'sms_enabled' => true,
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
            'quiet_hours_timezone' => null,
            'sound' => 'default',
        ];
    }
}
