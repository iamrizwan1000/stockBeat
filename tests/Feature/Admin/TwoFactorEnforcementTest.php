<?php

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an admin who has never confirmed 2FA is redirected to the security page from any admin route', function (string $role) {
    $admin = AdminUser::factory()->unconfirmedTwoFactor()->create(['role' => $role]);

    $this->actingAs($admin, 'admin')
        ->get('/admin')
        ->assertRedirect('/admin/security');

    $this->actingAs($admin, 'admin')
        ->get('/admin/customers')
        ->assertRedirect('/admin/security');
})->with([
    AdminUser::ROLE_SUPERADMIN,
    AdminUser::ROLE_SUPPORT,
    AdminUser::ROLE_READONLY,
]);

test('the security page itself stays reachable for an admin blocked on 2FA', function () {
    $admin = AdminUser::factory()->unconfirmedTwoFactor()->create();

    $this->actingAs($admin, 'admin')
        ->get('/admin/security')
        ->assertOk();
});

test('a blocked admin sees a banner explaining 2FA is required after being redirected', function () {
    $admin = AdminUser::factory()->unconfirmedTwoFactor()->create();

    $this->actingAs($admin, 'admin')
        ->get('/admin')
        ->assertRedirect('/admin/security');

    $this->actingAs($admin, 'admin')
        ->get('/admin/security')
        ->assertInertia(fn ($page) => $page->where('flash.twoFactorRequired', true));
});

test('the 2FA enable/confirm endpoints stay reachable while blocked (no lockout loop)', function () {
    $admin = AdminUser::factory()->unconfirmedTwoFactor()->create();

    $this->actingAs($admin, 'admin')
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/user/two-factor-authentication')
        ->assertOk();

    expect($admin->refresh()->two_factor_secret)->not->toBeNull();
});

test('logout stays reachable while blocked on 2FA', function () {
    $admin = AdminUser::factory()->unconfirmedTwoFactor()->create();

    $response = $this->actingAs($admin, 'admin')->post('/admin/logout');

    $this->assertGuest('admin');
    $response->assertRedirect('/');
});

test('an admin with confirmed 2FA passes through normally to every existing admin page', function () {
    $admin = AdminUser::factory()->create();

    $this->actingAs($admin, 'admin')->get('/admin')->assertOk();
    $this->actingAs($admin, 'admin')->get('/admin/customers')->assertOk();
    $this->actingAs($admin, 'admin')->get('/admin/security')->assertOk();
});
