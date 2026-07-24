<?php

use App\Models\AiUsageLedger;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\SmsLedger;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedUsageUser(): User
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

test('the usage summary endpoint requires authentication', function () {
    test()->getJson('/api/v1/usage/summary')->assertUnauthorized();
});

test('sms usage reports the real wallet balance separately from the monthly-allotment percentage', function () {
    $user = onboardedUsageUser();
    $team = $user->currentTeam();
    $team->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::PRO]);

    SmsLedger::factory()->create(['team_id' => $team->id, 'delta' => 100, 'balance_after' => 100]);
    SmsLedger::factory()->create(['team_id' => $team->id, 'delta' => -1, 'reason' => SmsLedger::REASON_SEND, 'balance_after' => 99]);
    SmsLedger::factory()->create(['team_id' => $team->id, 'delta' => -1, 'reason' => SmsLedger::REASON_SEND, 'balance_after' => 98]);

    $response = test()->getJson('/api/v1/usage/summary')->assertOk();

    expect($response->json('data.sms.balance'))->toBe(98);
    expect($response->json('data.sms.used_this_month'))->toBe(2);
    expect($response->json('data.sms.plan_monthly_allotment'))->toBe(100);
    expect($response->json('data.sms.pct_used'))->toBe(2);
    expect($response->json('data.sms.quota_warning'))->toBeFalse();
});

test('ai question usage nets a same-month top-up bonus into the effective limit, mirroring /me', function () {
    $user = onboardedUsageUser();
    $team = $user->currentTeam();
    $team->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::STARTER]);

    AiUsageLedger::factory()->count(24)->create(['team_id' => $team->id, 'reason' => AiUsageLedger::REASON_QUESTION]);
    AiUsageLedger::factory()->create(['team_id' => $team->id, 'delta' => 10, 'reason' => AiUsageLedger::REASON_TOPUP_IAP]);

    $response = test()->getJson('/api/v1/usage/summary')->assertOk();

    // Starter grants 30 AI questions/mo (Plan §5) + the 10-question top-up = 40 effective.
    expect($response->json('data.ai_questions.limit'))->toBe(40);
    expect($response->json('data.ai_questions.used_this_month'))->toBe(24);
    expect($response->json('data.ai_questions.remaining'))->toBe(16);
    expect($response->json('data.ai_questions.pct_used'))->toBe(60);
    expect($response->json('data.ai_questions.quota_warning'))->toBeFalse();
});

test('quota_warning flips true at 80% of the effective limit', function () {
    $user = onboardedUsageUser();
    $team = $user->currentTeam();
    $team->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::STARTER]);

    AiUsageLedger::factory()->count(25)->create(['team_id' => $team->id, 'reason' => AiUsageLedger::REASON_QUESTION]);

    $response = test()->getJson('/api/v1/usage/summary')->assertOk();

    // 25 of 30 = 83.3%, over the 80% threshold.
    expect($response->json('data.ai_questions.pct_used'))->toBe(83.3);
    expect($response->json('data.ai_questions.quota_warning'))->toBeTrue();
});

test('email usage counts only rule_email notifications sent to any team member this month', function () {
    $user = onboardedUsageUser();
    $team = $user->currentTeam();
    $team->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::PRO]);

    Notification::factory()->count(5)->create(['user_id' => $user->id, 'type' => Notification::TYPE_RULE_EMAIL]);
    Notification::factory()->create(['user_id' => $user->id, 'type' => Notification::TYPE_RULE_PUSH]);
    Notification::factory()->create(['user_id' => $user->id, 'type' => Notification::TYPE_RULE_EMAIL, 'created_at' => now()->subMonths(2)]);

    $response = test()->getJson('/api/v1/usage/summary')->assertOk();

    expect($response->json('data.emails.used_this_month'))->toBe(5);
    expect($response->json('data.emails.limit'))->toBe(1000);
    expect($response->json('data.emails.remaining'))->toBe(995);
});

test('a zero monthly limit (Free plan, AI not enabled) reports a null pct_used and never warns', function () {
    // Free's ai_questions_monthly is 0, not null — but a zero-or-null limit
    // both mean "no meaningful percentage to show," per quotaFields().
    $user = onboardedUsageUser();
    // A fresh signup is on an active Premium trial (Plan §6.3's "full-featured
    // trial") — force it expired to actually land on Free.
    $user->currentTeam()->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);

    $response = test()->getJson('/api/v1/usage/summary')->assertOk();

    expect($response->json('data.ai_questions.limit'))->toBe(0);
    expect($response->json('data.ai_questions.pct_used'))->toBeNull();
    expect($response->json('data.ai_questions.quota_warning'))->toBeFalse();
});

test('the daily series is a continuous 30-day window with zero-filled gaps', function () {
    $user = onboardedUsageUser();
    $team = $user->currentTeam();

    SmsLedger::factory()->create([
        'team_id' => $team->id,
        'delta' => -1,
        'reason' => SmsLedger::REASON_SEND,
        'created_at' => now(),
    ]);

    $response = test()->getJson('/api/v1/usage/summary')->assertOk();
    $daily = $response->json('data.sms.daily');

    expect($daily)->toHaveCount(30);
    expect($daily[0]['date'])->toBe(now()->subDays(29)->toDateString());
    expect($daily[29]['date'])->toBe(now()->toDateString());
    expect($daily[29]['count'])->toBe(1);
    expect($daily[0]['count'])->toBe(0);
});
