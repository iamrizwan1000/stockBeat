<?php

use App\Models\Plan;
use App\Models\SubscriptionEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedBillingHistoryUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
        'timezone' => 'UTC',
    ])->assertOk();

    return $user->fresh();
}

test('billing history requires authentication', function () {
    test()->getJson('/api/v1/billing/history')->assertUnauthorized();
});

test('billing history returns events newest first', function () {
    $user = onboardedBillingHistoryUser();
    $team = $user->currentTeam();

    SubscriptionEvent::factory()->create([
        'team_id' => $team->id,
        'event_type' => 'INITIAL_PURCHASE',
        'occurred_at' => now()->subMonths(2),
    ]);
    SubscriptionEvent::factory()->create([
        'team_id' => $team->id,
        'event_type' => 'RENEWAL',
        'occurred_at' => now()->subMonth(),
    ]);
    SubscriptionEvent::factory()->create([
        'team_id' => $team->id,
        'event_type' => 'PRODUCT_CHANGE',
        'occurred_at' => now()->subDay(),
    ]);

    $response = test()->getJson('/api/v1/billing/history');

    $response->assertOk();
    expect(collect($response->json('data.history'))->pluck('event_type')->all())
        ->toBe(['PRODUCT_CHANGE', 'RENEWAL', 'INITIAL_PURCHASE']);
});

test('billing history is an empty array for a team with no events', function () {
    onboardedBillingHistoryUser();

    $response = test()->getJson('/api/v1/billing/history');

    $response->assertOk();
    expect($response->json('data.history'))->toBe([]);
});

test('billing history never leaks another team\'s events', function () {
    $user = onboardedBillingHistoryUser();
    $otherTeam = Team::factory()->create();

    SubscriptionEvent::factory()->create([
        'team_id' => $user->currentTeam()->id,
        'event_type' => 'RENEWAL',
    ]);
    SubscriptionEvent::factory()->count(3)->create([
        'team_id' => $otherTeam->id,
        'event_type' => 'INITIAL_PURCHASE',
    ]);

    $response = test()->getJson('/api/v1/billing/history');

    $response->assertOk();
    $history = $response->json('data.history');
    expect($history)->toHaveCount(1);
    expect($history[0]['event_type'])->toBe('RENEWAL');
});

test('billing history maps raw revenuecat event types to seller-facing descriptions', function () {
    $user = onboardedBillingHistoryUser();
    $team = $user->currentTeam();

    $expected = [
        'INITIAL_PURCHASE' => 'Subscription started',
        'RENEWAL' => 'Renewed',
        'PRODUCT_CHANGE' => 'Plan changed',
        'CANCELLATION' => 'Auto-renew cancelled',
        'UNCANCELLATION' => 'Auto-renew resumed',
        'EXPIRATION' => 'Subscription expired',
        'BILLING_ISSUE' => 'Payment issue',
        'NON_RENEWING_PURCHASE' => 'Credit top-up',
    ];

    foreach (array_keys($expected) as $index => $eventType) {
        SubscriptionEvent::factory()->create([
            'team_id' => $team->id,
            'event_type' => $eventType,
            'occurred_at' => now()->subDays($index),
        ]);
    }

    $response = test()->getJson('/api/v1/billing/history');

    $response->assertOk();
    $byType = collect($response->json('data.history'))->keyBy('event_type');

    foreach ($expected as $eventType => $description) {
        expect($byType[$eventType]['description'])->toBe($description);
    }
});

test('an unrecognised event type falls back to a humanised label rather than an empty string', function () {
    $user = onboardedBillingHistoryUser();

    // RevenueCat can add event types without an app release — an unknown one
    // must still render as something readable, never blank.
    SubscriptionEvent::factory()->create([
        'team_id' => $user->currentTeam()->id,
        'event_type' => 'SOME_FUTURE_EVENT',
    ]);

    $response = test()->getJson('/api/v1/billing/history');

    $response->assertOk();
    expect($response->json('data.history.0.description'))->toBe('Some future event');
});

test('a credit top-up appears in billing history alongside subscription events', function () {
    $user = onboardedBillingHistoryUser();
    $team = $user->currentTeam();

    SubscriptionEvent::factory()->create([
        'team_id' => $team->id,
        'event_type' => 'RENEWAL',
        'price' => 19.99,
        'currency' => 'USD',
        'raw_payload' => ['type' => 'RENEWAL', 'product_id' => 'pro:monthly'],
        'occurred_at' => now()->subDays(2),
    ]);
    SubscriptionEvent::factory()->create([
        'team_id' => $team->id,
        'event_type' => 'NON_RENEWING_PURCHASE',
        'price' => 14.99,
        'currency' => 'USD',
        'raw_payload' => ['type' => 'NON_RENEWING_PURCHASE', 'product_id' => 'sms_500'],
        'occurred_at' => now()->subDay(),
    ]);

    $response = test()->getJson('/api/v1/billing/history');

    $response->assertOk();
    $history = $response->json('data.history');

    expect($history)->toHaveCount(2);
    expect($history[0]['event_type'])->toBe('NON_RENEWING_PURCHASE');
    expect($history[0]['product_id'])->toBe('sms_500');
    expect($history[0]['price'])->toBe(14.99);
    expect($history[1]['product_id'])->toBe('pro:monthly');
});

test('a null price stays null rather than becoming zero', function () {
    $user = onboardedBillingHistoryUser();

    // CANCELLATION/EXPIRATION payloads carry no price — reporting 0 would read
    // as a free transaction instead of an unknown amount.
    SubscriptionEvent::factory()->create([
        'team_id' => $user->currentTeam()->id,
        'event_type' => 'CANCELLATION',
        'price' => null,
        'currency' => null,
        'raw_payload' => ['type' => 'CANCELLATION'],
    ]);

    $response = test()->getJson('/api/v1/billing/history');

    $response->assertOk();
    expect($response->json('data.history.0.price'))->toBeNull();
    expect($response->json('data.history.0.currency'))->toBeNull();
});

test('product_id is null when the payload carried none', function () {
    $user = onboardedBillingHistoryUser();

    SubscriptionEvent::factory()->create([
        'team_id' => $user->currentTeam()->id,
        'event_type' => 'EXPIRATION',
        'raw_payload' => ['type' => 'EXPIRATION'],
    ]);

    $response = test()->getJson('/api/v1/billing/history');

    $response->assertOk();
    expect($response->json('data.history.0.product_id'))->toBeNull();
});

test('billing history is available on the Free plan, not gated behind a paid tier', function () {
    $user = onboardedBillingHistoryUser();
    $team = $user->currentTeam();

    // A lapsed team drops to Free entitlements but must still be able to see
    // what it was charged while it was paying.
    $team->subscription->update(['plan_key' => Plan::FREE, 'trial_ends_at' => now()->subDay()]);

    SubscriptionEvent::factory()->create([
        'team_id' => $team->id,
        'event_type' => 'EXPIRATION',
    ]);

    $response = test()->getJson('/api/v1/billing/history');

    $response->assertOk();
    expect($response->json('data.history'))->toHaveCount(1);
});
