<?php

namespace Database\Factories;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<AdminUser>
 */
class AdminUserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password = null;

    /**
     * Define the model's default state.
     *
     * 2FA is confirmed by default (§8.7 "mandatory 2FA" is enforced by
     * `EnsureAdminHasTwoFactorEnabled` on every admin route now) so the
     * ~15 other admin test files that don't care about 2FA at all keep
     * passing through that gate without each needing an explicit state
     * call. Tests that specifically exercise the "hasn't set up 2FA yet"
     * path (login-challenge behavior, the setup/confirm lifecycle) opt
     * into `unconfirmedTwoFactor()` instead.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => AdminUser::ROLE_SUPPORT,
            'two_factor_secret' => encrypt('test-two-factor-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode([
                'test-recovery-code-1',
                'test-recovery-code-2',
            ])),
            'two_factor_confirmed_at' => now(),
        ];
    }

    /**
     * Indicate that the admin is a superadmin.
     */
    public function superadmin(): static
    {
        return $this->state(fn () => ['role' => AdminUser::ROLE_SUPERADMIN]);
    }

    /**
     * Indicate that the admin is read-only.
     */
    public function readonly(): static
    {
        return $this->state(fn () => ['role' => AdminUser::ROLE_READONLY]);
    }

    /**
     * Indicate that the admin has never set up 2FA — the real default for a
     * freshly created admin, and the state used by tests that specifically
     * exercise the "not confirmed yet" path (login without a challenge, the
     * setup/confirm lifecycle, `EnsureAdminHasTwoFactorEnabled`'s redirect).
     */
    public function unconfirmedTwoFactor(): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
}
