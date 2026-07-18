<?php

namespace Database\Factories;

use App\Models\PromoCampaign;
use App\Models\PromoCampaignRedemption;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoCampaignRedemption>
 */
class PromoCampaignRedemptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promo_campaign_id' => PromoCampaign::factory()->serverComp(),
            'team_id' => Team::factory(),
            'redeemed_at' => now(),
            'subscription_event_id' => null,
        ];
    }
}
