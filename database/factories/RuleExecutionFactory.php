<?php

namespace Database\Factories;

use App\Models\Rule;
use App\Models\RuleExecution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuleExecution>
 */
class RuleExecutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rule_id' => Rule::factory(),
            'trigger' => Rule::TRIGGER_NEW_ORDER,
            'actions_result' => [['type' => 'push', 'status' => 'logged_only']],
            'fired_at' => now(),
        ];
    }
}
