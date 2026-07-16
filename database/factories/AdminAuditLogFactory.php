<?php

namespace Database\Factories;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminAuditLog>
 */
class AdminAuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admin_id' => AdminUser::factory(),
            'action' => 'test.action',
            'at' => now(),
        ];
    }
}
