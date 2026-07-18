<?php

namespace Database\Factories;

use App\Models\OpsHealthSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpsHealthSnapshot>
 */
class OpsHealthSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => now()->toDateString(),
            'active_teams' => 0,
            'mrr' => 0,
            'churned_teams' => 0,
            'total_orders_synced' => 0,
            'failed_jobs_total' => 0,
            'sms_cost_total' => 0,
        ];
    }
}
