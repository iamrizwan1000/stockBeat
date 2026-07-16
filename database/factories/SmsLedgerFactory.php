<?php

namespace Database\Factories;

use App\Models\SmsLedger;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsLedger>
 */
class SmsLedgerFactory extends Factory
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
            'delta' => 100,
            'reason' => SmsLedger::REASON_MONTHLY_GRANT,
            'balance_after' => 100,
        ];
    }
}
