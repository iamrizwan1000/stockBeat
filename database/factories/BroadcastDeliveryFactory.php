<?php

namespace Database\Factories;

use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BroadcastDelivery>
 */
class BroadcastDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'broadcast_id' => Broadcast::factory(),
            'user_id' => User::factory(),
            'channel' => Broadcast::CHANNEL_EMAIL,
            'status' => BroadcastDelivery::STATUS_SENT,
        ];
    }
}
