<?php

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the admin team page requires admin authentication', function () {
    test()->get('/admin/team')->assertRedirect('/admin/login');
});

test('the admin team page lists every admin user', function () {
    $admin = AdminUser::factory()->create(['name' => 'Jamie Support']);

    test()->actingAs($admin, 'admin')
        ->get('/admin/team')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/team/index')
            ->has('admins', 1)
            ->where('admins.0.name', 'Jamie Support')
        );
});

test('a superadmin can create a new admin user', function () {
    $admin = AdminUser::factory()->superadmin()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/team', [
            'name' => 'New Hire',
            'email' => 'new-hire@stockbeat.test',
            'password' => 'a-secure-password',
            'role' => AdminUser::ROLE_SUPPORT,
        ])
        ->assertRedirect();

    $newAdmin = AdminUser::query()->where('email', 'new-hire@stockbeat.test')->firstOrFail();
    expect($newAdmin->role)->toBe(AdminUser::ROLE_SUPPORT);
    expect($newAdmin->password)->not->toBe('a-secure-password');
});

test('a non-superadmin cannot create a new admin user', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/team', [
            'name' => 'New Hire',
            'email' => 'new-hire@stockbeat.test',
            'password' => 'a-secure-password',
            'role' => AdminUser::ROLE_SUPPORT,
        ])
        ->assertSessionHasErrors('role');

    expect(AdminUser::query()->where('email', 'new-hire@stockbeat.test')->exists())->toBeFalse();
});

test('a superadmin can change another admins role', function () {
    $admin = AdminUser::factory()->superadmin()->create();
    $target = AdminUser::factory()->create(['role' => AdminUser::ROLE_SUPPORT]);

    test()->actingAs($admin, 'admin')
        ->put("/admin/team/{$target->id}/role", ['role' => AdminUser::ROLE_READONLY])
        ->assertRedirect();

    expect($target->fresh()->role)->toBe(AdminUser::ROLE_READONLY);
});

test('a superadmin cannot change their own role', function () {
    $admin = AdminUser::factory()->superadmin()->create();

    test()->actingAs($admin, 'admin')
        ->put("/admin/team/{$admin->id}/role", ['role' => AdminUser::ROLE_SUPPORT])
        ->assertSessionHasErrors('role');

    expect($admin->fresh()->role)->toBe(AdminUser::ROLE_SUPERADMIN);
});

test('a superadmin can reset another admins 2FA', function () {
    $admin = AdminUser::factory()->superadmin()->create();
    $target = AdminUser::factory()->create([
        'two_factor_secret' => 'secret',
        'two_factor_recovery_codes' => 'codes',
        'two_factor_confirmed_at' => now(),
    ]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/team/{$target->id}/reset-2fa")
        ->assertRedirect();

    $target = $target->fresh();
    expect($target->two_factor_secret)->toBeNull();
    expect($target->two_factor_recovery_codes)->toBeNull();
    expect($target->two_factor_confirmed_at)->toBeNull();
});

test('a superadmin cannot reset their own 2FA', function () {
    $admin = AdminUser::factory()->superadmin()->create(['two_factor_secret' => 'secret']);

    test()->actingAs($admin, 'admin')
        ->post("/admin/team/{$admin->id}/reset-2fa")
        ->assertSessionHasErrors('admin');

    expect($admin->fresh()->two_factor_secret)->toBe('secret');
});

test('a superadmin can remove another admin user', function () {
    $admin = AdminUser::factory()->superadmin()->create();
    $target = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->delete("/admin/team/{$target->id}")
        ->assertRedirect();

    expect(AdminUser::query()->find($target->id))->toBeNull();
});

test('a superadmin cannot remove their own account', function () {
    $admin = AdminUser::factory()->superadmin()->create();

    test()->actingAs($admin, 'admin')
        ->delete("/admin/team/{$admin->id}")
        ->assertSessionHasErrors('admin');

    expect(AdminUser::query()->find($admin->id))->not->toBeNull();
});

test('a non-superadmin cannot remove an admin user', function () {
    $admin = AdminUser::factory()->create();
    $target = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->delete("/admin/team/{$target->id}")
        ->assertSessionHasErrors('admin');

    expect(AdminUser::query()->find($target->id))->not->toBeNull();
});
