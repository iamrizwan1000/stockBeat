<?php

namespace Database\Factories;

use App\Models\RevenueCatEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RevenueCatEvent>
 */
class RevenueCatEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) fake()->unique()->uuid(),
            'event_type' => 'INITIAL_PURCHASE',
            'processed_at' => now(),
        ];
    }
}
