<?php

namespace Database\Factories;

use App\Models\FeatureUsageEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureUsageEvent>
 */
class FeatureUsageEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'feature' => FeatureUsageEvent::FEATURE_INVOICE_GENERATED,
            'occurred_at' => now(),
        ];
    }
}
