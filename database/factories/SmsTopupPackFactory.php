<?php

namespace Database\Factories;

use App\Models\SmsTopupPack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsTopupPack>
 */
class SmsTopupPackFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'sms_'.fake()->unique()->numberBetween(50, 9999),
            'name' => fake()->words(2, true),
            'sms_credits' => 100,
            'price_usd' => 2.99,
            'active' => true,
            'sort_order' => 0,
        ];
    }
}
