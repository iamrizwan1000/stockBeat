<?php

use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\AiTopupPackSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SmsTopupPackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->seed(SmsTopupPackSeeder::class);
    $this->seed(AiTopupPackSeeder::class);
    config(['services.revenuecat.secret_api_key' => 'test-secret-key']);
});

/**
 * @param  array<string, mixed>  $subscriptions
 */
function fakeRevenueCatSubscriber(string $rcAppUserId, array $subscriptions): void
{
    Http::fake([
        "https://api.revenuecat.com/v1/subscribers/{$rcAppUserId}" => Http::response([
            'subscriber' => ['subscriptions' => $subscriptions],
        ]),
    ]);
}

test('GET billing/entitlements returns the same shape as /me entitlements', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['woo']])->assertOk();

    $response = test()->getJson('/api/v1/billing/entitlements');

    $response->assertOk();
    $response->assertJsonStructure(['data' => ['plan', 'limits', 'subscription_status', 'trial_ends_at', 'sms_balance', 'ai_questions_remaining']]);
    $response->assertJsonPath('data.subscription_status', Subscription::STATUS_TRIAL);
});

test('GET billing/entitlements fails cleanly when profile setup is not complete', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->getJson('/api/v1/billing/entitlements')->assertUnprocessable();
});

test('POST billing/sync activates the subscription from a real RevenueCat product and links rc_app_user_id', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['woo']])->assertOk();

    fakeRevenueCatSubscriber((string) $user->id, [
        'pro_monthly' => [
            'expires_date' => now()->addMonth()->toIso8601String(),
            'store' => 'app_store',
            'billing_issues_detected_at' => null,
        ],
    ]);

    $response = test()->postJson('/api/v1/billing/sync', ['rc_app_user_id' => (string) $user->id]);

    $response->assertOk()->assertJsonPath('data.plan', 'pro');

    $subscription = $user->ownedTeam->subscription->fresh();
    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE);
    expect($subscription->provider)->toBe('apple');
    expect($subscription->product_id)->toBe('pro_monthly');
    expect($subscription->rc_app_user_id)->toBe((string) $user->id);
});

test('POST billing/sync with a billing issue but unexpired subscription sets grace, still entitled', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['woo']])->assertOk();

    fakeRevenueCatSubscriber((string) $user->id, [
        'pro_monthly' => [
            'expires_date' => now()->addDays(3)->toIso8601String(),
            'store' => 'play_store',
            'billing_issues_detected_at' => now()->subDay()->toIso8601String(),
        ],
    ]);

    test()->postJson('/api/v1/billing/sync', ['rc_app_user_id' => (string) $user->id])->assertOk();

    $subscription = $user->ownedTeam->subscription->fresh();
    expect($subscription->status)->toBe(Subscription::STATUS_GRACE);
    expect($subscription->isEntitled())->toBeTrue();
});

test('POST billing/sync with an expired product downgrades entitlements to free', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['woo']])->assertOk();

    fakeRevenueCatSubscriber((string) $user->id, [
        'pro_monthly' => [
            'expires_date' => now()->subDay()->toIso8601String(),
            'store' => 'app_store',
            'billing_issues_detected_at' => null,
        ],
    ]);

    $response = test()->postJson('/api/v1/billing/sync', ['rc_app_user_id' => (string) $user->id]);

    $response->assertOk()->assertJsonPath('data.plan', 'free');
    expect($user->ownedTeam->subscription->fresh()->status)->toBe(Subscription::STATUS_EXPIRED);
});

test('POST billing/sync picks the highest tier when multiple products are present', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['woo']])->assertOk();

    fakeRevenueCatSubscriber((string) $user->id, [
        'starter_monthly' => [
            'expires_date' => now()->addMonth()->toIso8601String(),
            'store' => 'app_store',
            'billing_issues_detected_at' => null,
        ],
        'premium_monthly' => [
            'expires_date' => now()->addMonth()->toIso8601String(),
            'store' => 'app_store',
            'billing_issues_detected_at' => null,
        ],
    ]);

    test()->postJson('/api/v1/billing/sync', ['rc_app_user_id' => (string) $user->id])
        ->assertOk()
        ->assertJsonPath('data.plan', 'premium');
});

test('POST billing/sync with an unrecognized product is ignored and leaves entitlements untouched', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['woo']])->assertOk();

    fakeRevenueCatSubscriber((string) $user->id, [
        'some_other_apps_product' => [
            'expires_date' => now()->addMonth()->toIso8601String(),
            'store' => 'app_store',
            'billing_issues_detected_at' => null,
        ],
    ]);

    $response = test()->postJson('/api/v1/billing/sync', ['rc_app_user_id' => (string) $user->id]);

    $response->assertOk()->assertJsonPath('data.subscription_status', Subscription::STATUS_TRIAL);
});

test('POST billing/sync fails open when RevenueCat is unreachable — existing entitlements are returned unchanged', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['woo']])->assertOk();

    Http::fake([
        "https://api.revenuecat.com/v1/subscribers/{$user->id}" => Http::response(['error' => 'server error'], 500),
    ]);

    $response = test()->postJson('/api/v1/billing/sync', ['rc_app_user_id' => (string) $user->id]);

    $response->assertOk()->assertJsonPath('data.subscription_status', Subscription::STATUS_TRIAL);
});

test('POST billing/sync fails open when RevenueCat is not configured', function () {
    config(['services.revenuecat.secret_api_key' => null]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['woo']])->assertOk();

    Http::fake();

    $response = test()->postJson('/api/v1/billing/sync', ['rc_app_user_id' => (string) $user->id]);

    $response->assertOk()->assertJsonPath('data.subscription_status', Subscription::STATUS_TRIAL);
    Http::assertNothingSent();
});

test('POST billing/sync requires rc_app_user_id', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['woo']])->assertOk();

    test()->postJson('/api/v1/billing/sync', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rc_app_user_id');
});

test('POST billing/sync fails cleanly when profile setup is not complete', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/billing/sync', ['rc_app_user_id' => (string) $user->id])
        ->assertUnprocessable();
});
