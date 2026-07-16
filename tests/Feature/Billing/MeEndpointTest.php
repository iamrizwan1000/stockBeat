<?php

use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

test('me requires authentication', function () {
    test()->getJson('/api/v1/me')->assertUnauthorized();
});

test('a user who has not completed profile setup needs it', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.needs_profile_setup', true)
        ->assertJsonPath('data.team', null)
        ->assertJsonPath('data.entitlements', null);
});

test('a freshly onboarded team is on the premium trial', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['shopify'],
    ])->assertOk();

    $response = test()->getJson('/api/v1/me');

    $response->assertOk()
        ->assertJsonPath('data.needs_profile_setup', false)
        ->assertJsonPath('data.entitlements.plan', 'premium')
        ->assertJsonPath('data.entitlements.subscription_status', 'trial')
        ->assertJsonPath('data.entitlements.limits.max_stores', null)
        ->assertJsonPath('data.entitlements.sms_balance', 0);

    expect($response->json('data.entitlements.trial_ends_at'))->not->toBeNull();
});

test('entitlements fall back to free once the trial has expired', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['shopify'],
    ])->assertOk();

    Carbon::setTestNow(now()->addDays(8));

    test()->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.entitlements.plan', 'free')
        ->assertJsonPath('data.entitlements.limits.max_stores', 1);

    Carbon::setTestNow();
});
