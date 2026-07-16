<?php

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function validProfilePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jamie Seller',
        'business_name' => 'Jamie Goods',
        'sells_on' => ['shopify', 'etsy'],
        'timezone' => 'Australia/Sydney',
        'base_currency' => 'aud',
    ], $overrides);
}

test('profile setup requires authentication', function () {
    test()->postJson('/api/v1/profile/setup', validProfilePayload())->assertUnauthorized();
});

test('setting up a profile updates the user and creates their team', function () {
    $user = User::factory()->create(['name' => '']);
    Sanctum::actingAs($user);

    $response = test()->postJson('/api/v1/profile/setup', validProfilePayload());

    $response->assertOk()->assertJsonPath('data.user.name', 'Jamie Seller');

    $user->refresh();
    expect($user->name)->toBe('Jamie Seller');
    expect($user->business_name)->toBe('Jamie Goods');
    expect($user->sells_on)->toBe(['shopify', 'etsy']);
    expect($user->timezone)->toBe('Australia/Sydney');
    expect($user->base_currency)->toBe('AUD');

    $team = Team::query()->where('owner_id', $user->id)->sole();
    expect($team->name)->toBe('Jamie Goods');

    $membership = TeamMember::query()->where('team_id', $team->id)->sole();
    expect($membership->user_id)->toBe($user->id);
    expect($membership->role)->toBe(TeamMember::ROLE_OWNER);
});

test('calling profile setup twice does not create a duplicate team', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', validProfilePayload())->assertOk();
    test()->postJson('/api/v1/profile/setup', validProfilePayload(['name' => 'Updated Name']))->assertOk();

    expect(Team::query()->where('owner_id', $user->id)->count())->toBe(1);
    expect($user->refresh()->name)->toBe('Updated Name');
});

test('sells_on is required', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', validProfilePayload(['sells_on' => []]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sells_on');
});

test('sells_on rejects unknown platforms', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', validProfilePayload(['sells_on' => ['carrier-pigeon']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sells_on.0');
});

test('an invalid timezone is rejected', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', validProfilePayload(['timezone' => 'Mordor/Barad-dur']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('timezone');
});

test('business_name and timezone are optional', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Solo Seller',
        'sells_on' => ['amazon'],
    ])->assertOk();

    expect($user->refresh()->business_name)->toBeNull();
});
