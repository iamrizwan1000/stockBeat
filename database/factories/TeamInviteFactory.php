<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamInvite>
 */
class TeamInviteFactory extends Factory
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
            'email' => fake()->unique()->safeEmail(),
            'role' => TeamMember::ROLE_VIEWER,
            'store_visibility' => null,
            'invited_by_user_id' => User::factory(),
            'token' => Str::random(40),
            'status' => TeamInvite::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ];
    }
}
