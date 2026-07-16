<?php

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('notification preference endpoints require authentication', function () {
    test()->getJson('/api/v1/settings/notifications')->assertUnauthorized();
    test()->putJson('/api/v1/settings/notifications', [])->assertUnauthorized();
});

test('a user with no saved preferences sees sensible defaults', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->getJson('/api/v1/settings/notifications')
        ->assertOk()
        ->assertJsonPath('data.preferences.push_enabled', true)
        ->assertJsonPath('data.preferences.email_enabled', true)
        ->assertJsonPath('data.preferences.sms_enabled', true)
        ->assertJsonPath('data.preferences.quiet_hours_start', null)
        ->assertJsonPath('data.preferences.sound', 'default');
});

test('preferences can be updated and persist', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->putJson('/api/v1/settings/notifications', [
        'push_enabled' => false,
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '08:00',
        'quiet_hours_timezone' => 'Australia/Sydney',
        'sound' => 'chime',
    ])
        ->assertOk()
        ->assertJsonPath('data.preferences.push_enabled', false)
        ->assertJsonPath('data.preferences.quiet_hours_start', '22:00')
        ->assertJsonPath('data.preferences.sound', 'chime');

    expect(NotificationPreference::query()->where('user_id', $user->id)->first())
        ->push_enabled->toBeFalse()
        ->sound->toBe('chime');

    test()->getJson('/api/v1/settings/notifications')
        ->assertOk()
        ->assertJsonPath('data.preferences.push_enabled', false);
});

test('invalid quiet hours are rejected', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->putJson('/api/v1/settings/notifications', ['quiet_hours_start' => 'not-a-time'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quiet_hours_start');
});
