<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('logout and logout-all require authentication', function () {
    test()->postJson('/api/v1/auth/logout')->assertUnauthorized();
    test()->postJson('/api/v1/auth/logout-all')->assertUnauthorized();
});

test('logout revokes only the current token', function () {
    $user = User::factory()->create();
    $tokenA = $user->createToken('device-a')->plainTextToken;
    $user->createToken('device-b');

    test()->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    expect(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(1);
});

test('logout-all revokes every token for the user', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['*']);
    $user->createToken('device-a');
    $user->createToken('device-b');

    test()->postJson('/api/v1/auth/logout-all')->assertOk();

    expect(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(0);
});
