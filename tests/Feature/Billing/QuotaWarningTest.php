<?php

use App\Actions\Billing\CheckQuotaWarningsAction;
use App\Models\AiUsageLedger;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\QuotaWarningNotification;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No devices are ever registered in these tests, so `send()` is never
    // actually called — this just avoids the Messaging contract's real
    // Firebase service-account resolution firing on container boot.
    app()->instance(Messaging::class, Mockery::mock(Messaging::class));
});

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedQuotaWarningUser(): User
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

test('crossing 80% AI usage sends a push and logs a Notification Center row for the owner', function () {
    $user = onboardedQuotaWarningUser();
    $team = $user->currentTeam();
    $team->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::STARTER]);

    // Starter grants 30 AI questions/mo — 25 used = 83.3%, over the 80% threshold.
    AiUsageLedger::factory()->count(25)->create(['team_id' => $team->id, 'reason' => AiUsageLedger::REASON_QUESTION]);

    $sent = app(CheckQuotaWarningsAction::class)->handle($team->fresh());

    expect($sent)->toBe(1);
    expect(
        Notification::query()
            ->where('user_id', $team->owner_id)
            ->where('type', Notification::TYPE_QUOTA_WARNING)
            ->count()
    )->toBe(1);
    expect(QuotaWarningNotification::alreadySentThisMonth($team->id, QuotaWarningNotification::CHANNEL_AI_QUESTIONS))->toBeTrue();
});

test('running the check again the same month does not send a duplicate', function () {
    $user = onboardedQuotaWarningUser();
    $team = $user->currentTeam();
    $team->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::STARTER]);
    AiUsageLedger::factory()->count(25)->create(['team_id' => $team->id, 'reason' => AiUsageLedger::REASON_QUESTION]);

    app(CheckQuotaWarningsAction::class)->handle($team->fresh());
    $sentAgain = app(CheckQuotaWarningsAction::class)->handle($team->fresh());

    expect($sentAgain)->toBe(0);
    expect(
        Notification::query()
            ->where('user_id', $team->owner_id)
            ->where('type', Notification::TYPE_QUOTA_WARNING)
            ->count()
    )->toBe(1);
});

test('a team under 80% on every channel is not warned about any of them', function () {
    $user = onboardedQuotaWarningUser();
    $team = $user->currentTeam();
    $team->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::PRO]);

    $sent = app(CheckQuotaWarningsAction::class)->handle($team->fresh());

    expect($sent)->toBe(0);
});

test('a team can cross 80% on two channels in the same run and gets warned about both', function () {
    $user = onboardedQuotaWarningUser();
    $team = $user->currentTeam();
    $team->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::STARTER]);

    AiUsageLedger::factory()->count(25)->create(['team_id' => $team->id, 'reason' => AiUsageLedger::REASON_QUESTION]);
    // Starter grants 250 email/mo — 210 used = 84%.
    Notification::factory()->count(210)->create(['user_id' => $user->id, 'type' => Notification::TYPE_RULE_EMAIL]);

    $sent = app(CheckQuotaWarningsAction::class)->handle($team->fresh());

    expect($sent)->toBe(2);
    expect(QuotaWarningNotification::query()->where('team_id', $team->id)->count())->toBe(2);
});

test('the scheduled command checks every entitled team and skips non-entitled ones', function () {
    $user = onboardedQuotaWarningUser();
    $team = $user->currentTeam();
    $team->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::STARTER]);
    AiUsageLedger::factory()->count(25)->create(['team_id' => $team->id, 'reason' => AiUsageLedger::REASON_QUESTION]);

    $expiredUser = onboardedQuotaWarningUser();
    $expiredUser->currentTeam()->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);

    test()->artisan('usage:check-quota-warnings')
        ->expectsOutputToContain('sent 1 quota warning(s)')
        ->assertSuccessful();
});
