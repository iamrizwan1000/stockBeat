<?php

namespace Database\Factories;

use App\Models\AiUsageLedger;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageLedger>
 */
class AiUsageLedgerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'delta' => -1,
            'reason' => AiUsageLedger::REASON_QUESTION,
            'balance_after' => 0,
        ];
    }
}
