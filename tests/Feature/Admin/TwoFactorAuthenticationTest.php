<?php

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

test('the security page reports 2FA as disabled by default', function () {
    $admin = AdminUser::factory()->unconfirmedTwoFactor()->create();

    $this->actingAs($admin, 'admin')
        ->get('/admin/security')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('twoFactorEnabled', false));
});

test('enabling 2FA without a recent password confirmation is rejected', function () {
    $admin = AdminUser::factory()->create();

    $this->actingAs($admin, 'admin')
        ->postJson('/admin/user/two-factor-authentication')
        ->assertStatus(423);
});

test('an admin can enable, confirm, and disable 2FA', function () {
    $admin = AdminUser::factory()->unconfirmedTwoFactor()->create();

    $this->actingAs($admin, 'admin')
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/user/two-factor-authentication')
        ->assertOk();

    $admin->refresh();
    expect($admin->two_factor_secret)->not->toBeNull();
    expect($admin->two_factor_confirmed_at)->toBeNull();

    $secretKey = $this->actingAs($admin, 'admin')
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson('/admin/user/two-factor-secret-key')
        ->assertOk()
        ->json('secretKey');

    $validCode = app(Google2FA::class)->getCurrentOtp($secretKey);

    $this->actingAs($admin, 'admin')
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/user/confirmed-two-factor-authentication', ['code' => $validCode])
        ->assertOk();

    expect($admin->refresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->actingAs($admin, 'admin')
        ->get('/admin/security')
        ->assertInertia(fn ($page) => $page->where('twoFactorEnabled', true));

    $recoveryCodes = $this->actingAs($admin, 'admin')
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson('/admin/user/two-factor-recovery-codes')
        ->assertOk()
        ->json();

    expect($recoveryCodes)->toBeArray()->not->toBeEmpty();

    $this->actingAs($admin, 'admin')
        ->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson('/admin/user/two-factor-authentication')
        ->assertOk();

    expect($admin->refresh()->two_factor_secret)->toBeNull();
});

test('confirming with the wrong code fails', function () {
    $admin = AdminUser::factory()->unconfirmedTwoFactor()->create();

    $this->actingAs($admin, 'admin')
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/user/two-factor-authentication')
        ->assertOk();

    $this->actingAs($admin, 'admin')
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/user/confirmed-two-factor-authentication', ['code' => '000000'])
        ->assertUnprocessable();

    expect($admin->refresh()->two_factor_confirmed_at)->toBeNull();
});

test('a login for an admin with confirmed 2FA is challenged instead of let straight in', function () {
    $admin = AdminUser::factory()->create([
        'two_factor_secret' => encrypt('secret-key-value'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-one', 'code-two'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->post('/admin/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $this->assertGuest('admin');
    $response->assertRedirect('/admin/two-factor-challenge');
});

test('the two-factor challenge screen renders', function () {
    $admin = AdminUser::factory()->create([
        'two_factor_secret' => encrypt('secret-key-value'),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->post('/admin/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $this->get('/admin/two-factor-challenge')->assertOk();
});

test('a login for an admin without 2FA still goes straight in', function () {
    $admin = AdminUser::factory()->unconfirmedTwoFactor()->create();

    $response = $this->post('/admin/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated('admin');
    $response->assertRedirect('/admin');
});
