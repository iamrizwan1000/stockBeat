<?php

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the login screen can be rendered', function () {
    $this->get('/admin/login')->assertOk();
});

test('guests are redirected to the login screen', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

test('admins can authenticate with valid credentials', function () {
    $admin = AdminUser::factory()->unconfirmedTwoFactor()->create();

    $response = $this->post('/admin/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated('admin');
    $response->assertRedirect('/admin');
});

test('admins cannot authenticate with an invalid password', function () {
    $admin = AdminUser::factory()->create();

    $this->post('/admin/login', [
        'email' => $admin->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest('admin');
});

test('end users cannot authenticate on the admin guard', function () {
    $user = User::factory()->create();

    $this->post('/admin/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest('admin');
});

test('authenticated admins can view the dashboard', function () {
    $admin = AdminUser::factory()->create();

    $this->actingAs($admin, 'admin')->get('/admin')->assertOk();
});

test('admins can log out', function () {
    $admin = AdminUser::factory()->create();

    $response = $this->actingAs($admin, 'admin')->post('/admin/logout');

    $this->assertGuest('admin');
    $response->assertRedirect('/');
});

test('registration and password reset routes do not exist', function () {
    $this->get('/admin/register')->assertNotFound();
    $this->get('/register')->assertNotFound();
    $this->get('/admin/forgot-password')->assertNotFound();
    $this->get('/login')->assertNotFound();
});
