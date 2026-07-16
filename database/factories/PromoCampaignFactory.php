<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\PromoCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoCampaign>
 */
class PromoCampaignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'type' => PromoCampaign::TYPE_OFFER_CODE,
            'store_ref' => null,
            'config' => [],
            'starts_at' => null,
            'ends_at' => null,
            'created_by' => AdminUser::factory(),
            'stats' => null,
        ];
    }

    /**
     * @return static
     */
    public function serverComp(string $compType = PromoCampaign::COMP_TYPE_PRO_DAYS, int $amount = 30, ?int $segmentId = null): self
    {
        return $this->state([
            'type' => PromoCampaign::TYPE_SERVER_COMP,
            'store_ref' => null,
            'config' => ['comp_type' => $compType, 'amount' => $amount, 'segment_id' => $segmentId],
        ]);
    }
}
