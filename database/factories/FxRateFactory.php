<?php

namespace Database\Factories;

use App\Models\FxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FxRate>
 */
class FxRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'base' => 'USD',
            'quote' => 'AUD',
            'rate' => 1.5,
            'date' => now()->toDateString(),
        ];
    }
}
