<?php

namespace Database\Factories;

use App\Models\AiProviderSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProviderSetting>
 */
class AiProviderSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => AiProviderSetting::PROVIDER_GROQ,
            'api_key' => 'test-key-'.$this->faker->uuid(),
            'model' => 'llama-3.3-70b-versatile',
            'active' => true,
        ];
    }
}
