<?php

namespace Database\Factories;

use App\Models\QuotaWarningNotification;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuotaWarningNotification>
 */
class QuotaWarningNotificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'channel' => QuotaWarningNotification::CHANNEL_SMS,
        ];
    }
}
