<?php

namespace Database\Factories;

use App\Models\PushStormWindow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushStormWindow>
 */
class PushStormWindowFactory extends Factory
{
    protected $model = PushStormWindow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'window_started_at' => now(),
            'order_count' => 0,
            'revenue_total' => 0,
            'bundle_sent_at' => null,
        ];
    }
}
