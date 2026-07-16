<?php

use App\Models\Plan;
use App\Models\PlanLimit;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeds exactly 4 active plans', function () {
    $this->seed(PlanSeeder::class);

    expect(Plan::query()->where('active', true)->pluck('key')->sort()->values()->all())
        ->toBe(['free', 'premium', 'pro', 'starter']);
});

test('only Premium has advanced_triggers_enabled', function () {
    $this->seed(PlanSeeder::class);

    foreach ([Plan::FREE, Plan::STARTER, Plan::PRO] as $key) {
        $plan = Plan::query()->where('key', $key)->with('limits')->firstOrFail();
        expect($plan->limitsArray()[PlanLimit::ADVANCED_TRIGGERS_ENABLED])->toBeFalse();
    }

    $premium = Plan::query()->where('key', Plan::PREMIUM)->with('limits')->firstOrFail();
    expect($premium->limitsArray()[PlanLimit::ADVANCED_TRIGGERS_ENABLED])->toBeTrue();
});

test('store/rule/seat limits escalate across tiers', function () {
    $this->seed(PlanSeeder::class);

    $limits = collect([Plan::FREE, Plan::STARTER, Plan::PRO, Plan::PREMIUM])
        ->mapWithKeys(fn ($key) => [$key => Plan::query()->where('key', $key)->with('limits')->firstOrFail()->limitsArray()]);

    expect($limits[Plan::FREE][PlanLimit::MAX_STORES])->toBe(1);
    expect($limits[Plan::STARTER][PlanLimit::MAX_STORES])->toBe(3);
    expect($limits[Plan::PRO][PlanLimit::MAX_STORES])->toBe(10);
    expect($limits[Plan::PREMIUM][PlanLimit::MAX_STORES])->toBeNull();

    expect($limits[Plan::FREE][PlanLimit::MAX_RULES])->toBe(0);
    expect($limits[Plan::STARTER][PlanLimit::MAX_RULES])->toBe(5);
    expect($limits[Plan::PRO][PlanLimit::MAX_RULES])->toBeNull();

    expect($limits[Plan::FREE][PlanLimit::ANALYTICS_LEVEL])->toBe('today');
    expect($limits[Plan::STARTER][PlanLimit::ANALYTICS_LEVEL])->toBe('7d');
    expect($limits[Plan::PRO][PlanLimit::ANALYTICS_LEVEL])->toBe('full');

    expect($limits[Plan::PREMIUM][PlanLimit::TRIAL_DAYS])->toBe(7);
});
