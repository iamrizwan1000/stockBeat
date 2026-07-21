<?php

namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiConversation>
 */
class AiConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(4),
        ];
    }
}
