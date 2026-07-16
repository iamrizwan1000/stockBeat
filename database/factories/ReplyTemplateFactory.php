<?php

namespace Database\Factories;

use App\Models\ReplyTemplate;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReplyTemplate>
 */
class ReplyTemplateFactory extends Factory
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
            'name' => fake()->words(3, true),
            'body_with_variables' => 'Hi {customer_name}, your order {order_number} is on its way!',
        ];
    }
}
