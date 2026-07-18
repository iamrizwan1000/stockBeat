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
        // unconfirmedTwoFactor(): a real freshly-created admin has never set
        // up 2FA — the factory's own default state is a fake-but-confirmed
        // secret purely for test convenience (see AdminUserFactory), which
        // would otherwise seed this dev-login account with a 2FA challenge
        // nobody ever got a real QR code for.
        AdminUser::factory()->superadmin()->unconfirmedTwoFactor()->create([
            'name' => 'Super Admin',
            'email' => 'admin@stockbeat.test',
        ]);

        $this->call(PlanSeeder::class);
        $this->call(SmsTopupPackSeeder::class);
        $this->call(ContentBlockSeeder::class);
    }
}
