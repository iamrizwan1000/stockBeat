<?php

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('device registration requires authentication', function () {
    test()->postJson('/api/v1/devices', ['platform' => 'ios', 'push_token' => 'abc'])
        ->assertUnauthorized();
});

test('a device can be registered', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/devices', ['platform' => 'ios', 'push_token' => 'token-abc'])
        ->assertCreated()
        ->assertJsonPath('data.device.platform', 'ios');

    expect(Device::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('re-registering the same token updates instead of duplicating', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/devices', ['platform' => 'ios', 'push_token' => 'token-abc'])->assertCreated();
    test()->postJson('/api/v1/devices', ['platform' => 'ios', 'push_token' => 'token-abc'])->assertCreated();

    expect(Device::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('an invalid platform is rejected', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/devices', ['platform' => 'windows-phone', 'push_token' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('platform');
});
