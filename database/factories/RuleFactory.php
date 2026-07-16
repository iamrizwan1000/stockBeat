<?php

namespace Database\Factories;

use App\Models\Rule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rule>
 */
class RuleFactory extends Factory
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
            'name' => fake()->sentence(3),
            'trigger' => Rule::TRIGGER_NEW_ORDER,
            'conditions' => null,
            'actions' => [['type' => 'push']],
            'controls' => null,
            'enabled' => true,
            'created_by' => User::factory(),
        ];
    }
}
