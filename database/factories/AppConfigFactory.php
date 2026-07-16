<?php

namespace Database\Factories;

use App\Models\AppConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppConfig>
 */
class AppConfigFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => AppConfig::KEY_MIN_VERSION,
            'value' => '1.0.0',
            'updated_by' => null,
        ];
    }
}
