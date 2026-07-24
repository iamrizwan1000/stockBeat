<?php

use App\Actions\Billing\GrantMonthlySmsCreditsAction;
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

function onboardedGrantTestUser(): User
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

test('a fresh trial signup is granted the Premium allotment immediately, not on a later job run', function () {
    $user = onboardedGrantTestUser();

    expect(SmsLedger::currentBalance($user->currentTeam()->id))->toBe(500);
    expect(
        SmsLedger::query()
            ->where('team_id', $user->currentTeam()->id)
            ->where('reason', SmsLedger::REASON_MONTHLY_GRANT)
            ->count()
    )->toBe(1);
});

test('calling the action twice in the same calendar month does not double-grant', function () {
    $user = onboardedGrantTestUser();
    $team = $user->currentTeam();
    $balanceAfterSignup = SmsLedger::currentBalance($team->id);

    $grantedAgain = app(GrantMonthlySmsCreditsAction::class)->handle($team->fresh());

    expect($grantedAgain)->toBeFalse();
    expect(SmsLedger::currentBalance($team->id))->toBe($balanceAfterSignup);
});

test('a team on a plan with no SMS allotment (Free) is never granted anything new', function () {
    $user = onboardedGrantTestUser();
    $team = $user->currentTeam();
    // Falling back to Free (no SMS at all) shouldn't wipe whatever balance
    // the team already had — same "credit never disappears" principle as
    // a top-up. This asserts no *new* grant happens, not that the balance resets.
    $team->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);
    $balanceBefore = SmsLedger::currentBalance($team->id);

    $granted = app(GrantMonthlySmsCreditsAction::class)->handle($team->fresh());

    expect($granted)->toBeFalse();
    expect(SmsLedger::currentBalance($team->id))->toBe($balanceBefore);
});

test('the scheduled command grants credits to every entitled team and skips already-granted ones', function () {
    $user = onboardedGrantTestUser();
    $team = $user->currentTeam();
    $team->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::STARTER]);

    // Simulate the trial-grant firing on the old Premium plan_key before the
    // downgrade above — reset the ledger to isolate this test's own run.
    SmsLedger::query()->where('team_id', $team->id)->delete();

    test()->artisan('sms:grant-monthly-credits')
        ->expectsOutputToContain('granted 1 new monthly SMS credit(s)')
        ->assertSuccessful();

    expect(SmsLedger::currentBalance($team->id))->toBe(20);

    // Running it again the same month grants nothing new.
    test()->artisan('sms:grant-monthly-credits')
        ->expectsOutputToContain('granted 0 new monthly SMS credit(s)')
        ->assertSuccessful();

    expect(SmsLedger::currentBalance($team->id))->toBe(20);
});

test('an expired subscription is not granted anything by the scheduled command', function () {
    $user = onboardedGrantTestUser();
    $team = $user->currentTeam();
    $team->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);
    SmsLedger::query()->where('team_id', $team->id)->delete();

    test()->artisan('sms:grant-monthly-credits')->assertSuccessful();

    expect(SmsLedger::currentBalance($team->id))->toBe(0);
});
