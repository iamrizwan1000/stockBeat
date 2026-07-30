<?php

use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

test('plans requires authentication', function () {
    test()->getJson('/api/v1/billing/plans')->assertUnauthorized();
});

test('plans returns all active plans with their limits, in a stable order', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = test()->getJson('/api/v1/billing/plans');

    $response->assertOk();
    $plans = $response->json('data');

    expect($plans)->toHaveCount(4);
    expect(collect($plans)->pluck('key')->all())->toBe(['free', 'starter', 'pro', 'premium']);

    $starter = collect($plans)->firstWhere('key', 'starter');
    expect($starter['name'])->toBe('Starter');
    expect($starter['limits']['max_stores'])->toBe(3);
    expect($starter['limits']['sms_monthly'])->toBe(20);

    $premium = collect($plans)->firstWhere('key', 'premium');
    expect($premium['limits']['max_stores'])->toBeNull();
});

test('plans reflects an admin edit to plan_limits immediately', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $starter = Plan::query()->where('key', Plan::STARTER)->firstOrFail();
    PlanLimit::query()
        ->where('plan_id', $starter->id)
        ->where('key', PlanLimit::MAX_STORES)
        ->update(['value' => 5]);

    $response = test()->getJson('/api/v1/billing/plans');

    $starterData = collect($response->json('data'))->firstWhere('key', 'starter');
    expect($starterData['limits']['max_stores'])->toBe(5);
});

test('plans excludes inactive plans', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Plan::query()->where('key', Plan::PREMIUM)->update(['active' => false]);

    $response = test()->getJson('/api/v1/billing/plans');

    expect(collect($response->json('data'))->pluck('key'))->not->toContain('premium');
});
