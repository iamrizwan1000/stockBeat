<?php

namespace Database\Factories;

use App\Models\SubscriptionEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionEvent>
 */
class SubscriptionEventFactory extends Factory
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
            'event_type' => 'INITIAL_PURCHASE',
            'price' => null,
            'currency' => null,
            'raw_payload' => ['type' => 'INITIAL_PURCHASE'],
            'occurred_at' => now(),
        ];
    }
}
