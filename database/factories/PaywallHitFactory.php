<?php

namespace Database\Factories;

use App\Models\PaywallHit;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaywallHit>
 */
class PaywallHitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'limit_key' => PlanLimit::MAX_STORES,
            'plan_key' => Plan::FREE,
            'occurred_at' => now(),
        ];
    }
}
