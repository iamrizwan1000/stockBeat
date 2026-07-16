<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        AdminUser::factory()->superadmin()->create([
            'name' => 'Super Admin',
            'email' => 'admin@stockbeat.test',
        ]);

        $this->call(PlanSeeder::class);
    }
}
