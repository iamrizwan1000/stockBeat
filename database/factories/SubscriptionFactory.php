<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
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
            'status' => Subscription::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays(7),
        ];
    }
}
