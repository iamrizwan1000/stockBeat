<?php

use App\Models\AiTopupPack;
use App\Models\AiUsageLedger;
use App\Models\ContentBlock;
use App\Models\FeatureFlag;
use App\Models\Notification;
use App\Models\SmsTopupPack;
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

test('me includes active sms top-up packs and excludes inactive ones, sorted by sort_order', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    SmsTopupPack::factory()->create(['key' => 'sms_500', 'name' => '500 SMS', 'sms_credits' => 500, 'price_usd' => 9.99, 'active' => true, 'sort_order' => 2]);
    SmsTopupPack::factory()->create(['key' => 'sms_100', 'name' => '100 SMS', 'sms_credits' => 100, 'price_usd' => 2.99, 'active' => true, 'sort_order' => 1]);
    SmsTopupPack::factory()->create(['key' => 'sms_retired', 'active' => false]);

    $response = test()->getJson('/api/v1/me');

    $response->assertOk();
    $packs = $response->json('data.sms_topup_packs');

    expect($packs)->toHaveCount(2);
    expect($packs[0]['key'])->toBe('sms_100');
    expect($packs[1]['key'])->toBe('sms_500');
    expect(collect($packs)->pluck('key'))->not->toContain('sms_retired');
});

test('me includes active ai top-up packs and excludes inactive ones, sorted by sort_order', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    AiTopupPack::factory()->create(['key' => 'ai_200', 'name' => '200 AI questions', 'ai_questions' => 200, 'price_usd' => 14.99, 'active' => true, 'sort_order' => 2]);
    AiTopupPack::factory()->create(['key' => 'ai_50', 'name' => '50 AI questions', 'ai_questions' => 50, 'price_usd' => 4.99, 'active' => true, 'sort_order' => 1]);
    AiTopupPack::factory()->create(['key' => 'ai_retired', 'active' => false]);

    $response = test()->getJson('/api/v1/me');

    $response->assertOk();
    $packs = $response->json('data.ai_topup_packs');

    expect($packs)->toHaveCount(2);
    expect($packs[0]['key'])->toBe('ai_50');
    expect($packs[1]['key'])->toBe('ai_200');
    expect(collect($packs)->pluck('key'))->not->toContain('ai_retired');
});

test('me reports ai_questions_remaining and it drops as questions are used, and rises with a topup', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['shopify'],
    ])->assertOk();
    $team = $user->fresh()->currentTeam();

    // Premium trial grants 500 ai_questions_monthly (PlanSeeder).
    test()->getJson('/api/v1/me')->assertJsonPath('data.entitlements.ai_questions_remaining', 500);

    AiUsageLedger::query()->create([
        'team_id' => $team->id,
        'delta' => -1,
        'reason' => AiUsageLedger::REASON_QUESTION,
        'balance_after' => 499,
    ]);

    test()->getJson('/api/v1/me')->assertJsonPath('data.entitlements.ai_questions_remaining', 499);

    AiUsageLedger::query()->create([
        'team_id' => $team->id,
        'delta' => 50,
        'reason' => AiUsageLedger::REASON_TOPUP_IAP,
        'balance_after' => 50,
    ]);

    test()->getJson('/api/v1/me')->assertJsonPath('data.entitlements.ai_questions_remaining', 549);
});

test('me reports emails_remaining and it drops as rule emails are sent', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['shopify'],
    ])->assertOk();

    // Premium trial grants 5000 email_monthly (PlanSeeder).
    test()->getJson('/api/v1/me')->assertJsonPath('data.entitlements.emails_remaining', 5000);

    Notification::factory()->create([
        'user_id' => $user->id,
        'type' => Notification::TYPE_RULE_EMAIL,
    ]);

    test()->getJson('/api/v1/me')->assertJsonPath('data.entitlements.emails_remaining', 4999);
});

test('me includes active content blocks and excludes inactive ones', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    ContentBlock::factory()->create(['key' => 'paywall_pro_headline', 'body' => 'Pro — $17.99/month', 'locale' => 'en', 'active' => true]);
    ContentBlock::factory()->create(['key' => 'paywall_retired', 'body' => 'Should not appear', 'active' => false]);

    $response = test()->getJson('/api/v1/me');

    $response->assertOk()
        ->assertJsonPath('data.content.paywall_pro_headline', 'Pro — $17.99/month');

    expect($response->json('data.content'))->not->toHaveKey('paywall_retired');
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

test('me includes correctly-evaluated feature flags for the team', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['shopify'],
    ])->assertOk();

    $teamId = test()->getJson('/api/v1/me')->json('data.team.id');

    FeatureFlag::factory()->create([
        'key' => 'master_off',
        'enabled' => false,
        'rollout_percentage' => 100,
    ]);
    FeatureFlag::factory()->create([
        'key' => 'allow_listed',
        'enabled' => true,
        'rollout_percentage' => 0,
        'enabled_for_team_ids' => [$teamId],
    ]);
    FeatureFlag::factory()->create([
        'key' => 'full_rollout',
        'enabled' => true,
        'rollout_percentage' => 100,
    ]);
    FeatureFlag::factory()->create([
        'key' => 'zero_rollout',
        'enabled' => true,
        'rollout_percentage' => 0,
    ]);

    $response = test()->getJson('/api/v1/me');

    $response->assertOk()
        ->assertJsonPath('data.feature_flags.master_off', false)
        ->assertJsonPath('data.feature_flags.allow_listed', true)
        ->assertJsonPath('data.feature_flags.full_rollout', true)
        ->assertJsonPath('data.feature_flags.zero_rollout', false);
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
