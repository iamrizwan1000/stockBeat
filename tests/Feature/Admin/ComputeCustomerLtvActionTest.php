<?php

use App\Actions\Admin\ComputeCustomerLtvAction;
use App\Models\FxRate;
use App\Models\SubscriptionEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('LTV sums priced events already in the base currency with no conversion needed', function () {
    $owner = User::factory()->create(['base_currency' => 'USD']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);

    SubscriptionEvent::factory()->create(['team_id' => $team->id, 'price' => 9.99, 'currency' => 'USD']);
    SubscriptionEvent::factory()->create(['team_id' => $team->id, 'price' => 4.99, 'currency' => 'USD']);

    $ltv = app(ComputeCustomerLtvAction::class)->handle($team);

    expect($ltv['total'])->toBe(14.98);
    expect($ltv['currency'])->toBe('USD');
    expect($ltv['events_included'])->toBe(2);
    expect($ltv['events_excluded_no_price'])->toBe(0);
    expect($ltv['events_excluded_no_fx_rate'])->toBe(0);
});

test('LTV converts a foreign-currency event using the FX rate on or before it occurred', function () {
    $owner = User::factory()->create(['base_currency' => 'USD']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);

    FxRate::factory()->create(['base' => 'USD', 'quote' => 'AUD', 'rate' => 1.5, 'date' => now()->toDateString()]);

    SubscriptionEvent::factory()->create([
        'team_id' => $team->id,
        'price' => 15.0,
        'currency' => 'AUD',
        'occurred_at' => now(),
    ]);

    $ltv = app(ComputeCustomerLtvAction::class)->handle($team);

    expect($ltv['total'])->toBe(10.0);
    expect($ltv['events_included'])->toBe(1);
});

test('LTV excludes events with no price and events with no available FX rate, without fabricating numbers', function () {
    $owner = User::factory()->create(['base_currency' => 'USD']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);

    // No price at all (e.g. a CANCELLATION event).
    SubscriptionEvent::factory()->create(['team_id' => $team->id, 'price' => null, 'currency' => null]);
    // Priced, but in a currency with no FxRate row for that date.
    SubscriptionEvent::factory()->create(['team_id' => $team->id, 'price' => 20.0, 'currency' => 'JPY']);

    $ltv = app(ComputeCustomerLtvAction::class)->handle($team);

    expect($ltv['total'])->toBe(0.0);
    expect($ltv['events_included'])->toBe(0);
    expect($ltv['events_excluded_no_price'])->toBe(1);
    expect($ltv['events_excluded_no_fx_rate'])->toBe(1);
});

test('a team with no subscription events has zero LTV', function () {
    $owner = User::factory()->create(['base_currency' => 'USD']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);

    $ltv = app(ComputeCustomerLtvAction::class)->handle($team);

    expect($ltv['total'])->toBe(0.0);
    expect($ltv['events_included'])->toBe(0);
});
