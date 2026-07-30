<?php

namespace Database\Factories;

use App\Models\EmailUsageLedger;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailUsageLedger>
 */
class EmailUsageLedgerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'delta' => 50,
            'reason' => EmailUsageLedger::REASON_TOPUP_IAP,
            'balance_after' => 50,
        ];
    }
}
