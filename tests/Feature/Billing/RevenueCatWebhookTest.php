<?php

use App\Models\SmsLedger;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SmsTopupPackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->seed(SmsTopupPackSeeder::class);
    config(['services.revenuecat.webhook_secret' => 'test-secret']);
});

function onboardedRevenueCatUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $event
 */
function postRevenueCatEvent(array $event, ?string $bearer = 'test-secret'): TestResponse
{
    $headers = $bearer !== null ? ['Authorization' => "Bearer {$bearer}"] : [];

    return test()->postJson('/hooks/revenuecat', ['api_version' => '1.0', 'event' => $event], $headers);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function revenueCatEvent(int $appUserId, array $overrides = []): array
{
    return array_merge([
        'id' => (string) fake()->unique()->uuid(),
        'type' => 'INITIAL_PURCHASE',
        'app_user_id' => (string) $appUserId,
        'product_id' => 'pro_monthly',
        'store' => 'PLAY_STORE',
        'expiration_at_ms' => now()->addMonth()->getTimestampMs(),
    ], $overrides);
}

test('a missing or invalid Authorization header is rejected', function () {
    $user = onboardedRevenueCatUser();

    postRevenueCatEvent(revenueCatEvent($user->id), null)->assertUnauthorized();
    postRevenueCatEvent(revenueCatEvent($user->id), 'wrong-secret')->assertUnauthorized();
});

test('an INITIAL_PURCHASE activates the subscription and is reflected in /me', function () {
    $user = onboardedRevenueCatUser();
    expect($user->ownedTeam->subscription->status)->toBe(Subscription::STATUS_TRIAL);

    postRevenueCatEvent(revenueCatEvent($user->id, ['product_id' => 'pro_monthly']))->assertOk();

    $subscription = $user->ownedTeam->subscription->fresh();
    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE);
    expect($subscription->provider)->toBe('google');
    expect($subscription->product_id)->toBe('pro_monthly');
    expect($subscription->plan_key)->toBe('pro');
    expect($subscription->expires_at)->not->toBeNull();

    test()->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.entitlements.plan', 'pro');
});

test('starter_monthly and premium_yearly each activate their own tier', function () {
    $starterUser = onboardedRevenueCatUser();
    postRevenueCatEvent(revenueCatEvent($starterUser->id, ['product_id' => 'starter_monthly']))->assertOk();
    expect($starterUser->ownedTeam->subscription->fresh()->plan_key)->toBe('starter');

    $premiumUser = onboardedRevenueCatUser();
    postRevenueCatEvent(revenueCatEvent($premiumUser->id, ['product_id' => 'premium_yearly']))->assertOk();
    expect($premiumUser->ownedTeam->subscription->fresh()->plan_key)->toBe('premium');
});

test('a PRODUCT_CHANGE moves plan_key to the new tier', function () {
    $user = onboardedRevenueCatUser();
    postRevenueCatEvent(revenueCatEvent($user->id, ['type' => 'INITIAL_PURCHASE', 'product_id' => 'pro_monthly']))->assertOk();
    expect($user->ownedTeam->subscription->fresh()->plan_key)->toBe('pro');

    postRevenueCatEvent(revenueCatEvent($user->id, ['type' => 'PRODUCT_CHANGE', 'product_id' => 'premium_monthly']))->assertOk();
    expect($user->ownedTeam->subscription->fresh()->plan_key)->toBe('premium');
});

test('BILLING_ISSUE moves the subscription to grace and it stays entitled', function () {
    $user = onboardedRevenueCatUser();
    postRevenueCatEvent(revenueCatEvent($user->id, ['type' => 'INITIAL_PURCHASE']))->assertOk();

    postRevenueCatEvent(revenueCatEvent($user->id, ['type' => 'BILLING_ISSUE']))->assertOk();

    $subscription = $user->ownedTeam->subscription->fresh();
    expect($subscription->status)->toBe(Subscription::STATUS_GRACE);
    expect($subscription->isEntitled())->toBeTrue();
});

test('EXPIRATION moves the subscription to expired and entitlements revert to free', function () {
    $user = onboardedRevenueCatUser();
    postRevenueCatEvent(revenueCatEvent($user->id, ['type' => 'INITIAL_PURCHASE']))->assertOk();

    postRevenueCatEvent(revenueCatEvent($user->id, ['type' => 'EXPIRATION']))->assertOk();

    $subscription = $user->ownedTeam->subscription->fresh();
    expect($subscription->status)->toBe(Subscription::STATUS_EXPIRED);
    expect($subscription->isEntitled())->toBeFalse();

    test()->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.entitlements.plan', 'free');
});

test('CANCELLATION does not change status — stays active until it actually expires', function () {
    $user = onboardedRevenueCatUser();
    postRevenueCatEvent(revenueCatEvent($user->id, ['type' => 'INITIAL_PURCHASE']))->assertOk();

    postRevenueCatEvent(revenueCatEvent($user->id, ['type' => 'CANCELLATION']))->assertOk();

    $subscription = $user->ownedTeam->subscription->fresh();
    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE);
});

test('a NON_RENEWING_PURCHASE for sms_100 credits 100 to the sms ledger', function () {
    $user = onboardedRevenueCatUser();

    postRevenueCatEvent(revenueCatEvent($user->id, [
        'type' => 'NON_RENEWING_PURCHASE',
        'product_id' => 'sms_100',
    ]))->assertOk();

    expect(SmsLedger::currentBalance($user->ownedTeam->id))->toBe(100);
});

test('a duplicate event id is processed only once', function () {
    $user = onboardedRevenueCatUser();
    $event = revenueCatEvent($user->id, ['type' => 'NON_RENEWING_PURCHASE', 'product_id' => 'sms_500']);

    postRevenueCatEvent($event)->assertOk();
    postRevenueCatEvent($event)->assertOk()->assertJsonPath('status', 'duplicate');

    expect(SmsLedger::currentBalance($user->ownedTeam->id))->toBe(500);
    expect(SmsLedger::query()->where('team_id', $user->ownedTeam->id)->count())->toBe(1);
});

test('an unknown app_user_id is safely ignored', function () {
    postRevenueCatEvent(revenueCatEvent(999999))->assertOk();
});

test('an unrecognized product_id is a no-op, not a silent Pro grant', function () {
    $user = onboardedRevenueCatUser();

    postRevenueCatEvent(revenueCatEvent($user->id, ['product_id' => 'some_future_sku']))->assertOk();

    expect($user->ownedTeam->subscription->fresh()->status)->toBe(Subscription::STATUS_TRIAL);
});

test('an INITIAL_PURCHASE with price data is appended to the subscription_events timeline', function () {
    $user = onboardedRevenueCatUser();

    postRevenueCatEvent(revenueCatEvent($user->id, [
        'type' => 'INITIAL_PURCHASE',
        'product_id' => 'pro_monthly',
        'price_in_purchased_currency' => 9.99,
        'currency' => 'USD',
    ]))->assertOk();

    $event = SubscriptionEvent::query()->where('team_id', $user->ownedTeam->id)->first();
    expect($event)->not->toBeNull();
    expect($event->event_type)->toBe('INITIAL_PURCHASE');
    expect($event->price)->toBe(9.99);
    expect($event->currency)->toBe('USD');
});

test('a CANCELLATION with no price data is still logged to the timeline with a null price', function () {
    $user = onboardedRevenueCatUser();
    postRevenueCatEvent(revenueCatEvent($user->id, ['type' => 'INITIAL_PURCHASE']))->assertOk();

    postRevenueCatEvent(revenueCatEvent($user->id, ['type' => 'CANCELLATION']))->assertOk();

    $cancellation = SubscriptionEvent::query()
        ->where('team_id', $user->ownedTeam->id)
        ->where('event_type', 'CANCELLATION')
        ->first();

    expect($cancellation)->not->toBeNull();
    expect($cancellation->price)->toBeNull();
    expect($cancellation->currency)->toBeNull();
});

test('a NON_RENEWING_PURCHASE SMS top-up is also appended to the subscription_events timeline', function () {
    $user = onboardedRevenueCatUser();

    postRevenueCatEvent(revenueCatEvent($user->id, [
        'type' => 'NON_RENEWING_PURCHASE',
        'product_id' => 'sms_100',
        'price' => 4.99,
    ]))->assertOk();

    $event = SubscriptionEvent::query()->where('team_id', $user->ownedTeam->id)->first();
    expect($event)->not->toBeNull();
    expect($event->event_type)->toBe('NON_RENEWING_PURCHASE');
    expect($event->price)->toBe(4.99);
    expect($event->currency)->toBe('USD');
});

test('an unrecognized product_id event is not appended to the subscription_events timeline', function () {
    $user = onboardedRevenueCatUser();

    postRevenueCatEvent(revenueCatEvent($user->id, ['product_id' => 'some_future_sku']))->assertOk();

    expect(SubscriptionEvent::query()->where('team_id', $user->ownedTeam->id)->count())->toBe(0);
});

test('a duplicate event id does not double-append to the subscription_events timeline', function () {
    $user = onboardedRevenueCatUser();
    $event = revenueCatEvent($user->id, ['type' => 'INITIAL_PURCHASE']);

    postRevenueCatEvent($event)->assertOk();
    postRevenueCatEvent($event)->assertOk()->assertJsonPath('status', 'duplicate');

    expect(SubscriptionEvent::query()->where('team_id', $user->ownedTeam->id)->count())->toBe(1);
});
