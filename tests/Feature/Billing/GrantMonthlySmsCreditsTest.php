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
    // Also clear the signup flow's own 30s idempotency lock so this test's
    // deliberately-reset ledger state isn't itself blocked by a stale lock.
    test()->travel(31)->seconds();
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

test('two rapid back-to-back calls for the same team only ever create one grant row', function () {
    $user = onboardedGrantTestUser();
    $team = $user->currentTeam()->fresh();
    // The signup flow already granted once (and holds its own 30s lock) —
    // travel past that lock and reset the ledger so this test starts clean
    // and exercises the action's own idempotency guard directly, not the
    // signup flow's.
    test()->travel(31)->seconds();
    SmsLedger::query()->where('team_id', $team->id)->delete();

    $first = app(GrantMonthlySmsCreditsAction::class)->handle($team);
    $second = app(GrantMonthlySmsCreditsAction::class)->handle($team);

    expect($first)->toBeTrue();
    expect($second)->toBeFalse();
    expect(
        SmsLedger::query()
            ->where('team_id', $team->id)
            ->where('reason', SmsLedger::REASON_MONTHLY_GRANT)
            ->count()
    )->toBe(1);
});

test('a call made after the lock expires but still in the same month is still blocked by the durable exists() check', function () {
    $user = onboardedGrantTestUser();
    $team = $user->currentTeam()->fresh();
    // Clear the signup flow's own lock before this test's first real call.
    test()->travel(31)->seconds();
    SmsLedger::query()->where('team_id', $team->id)->delete();

    $first = app(GrantMonthlySmsCreditsAction::class)->handle($team);
    // Past the action's 30s IdempotencyGuard lock TTL, but the calendar
    // month hasn't changed — the lock expiring must not reopen the door to
    // a second real grant this month.
    test()->travel(31)->seconds();
    $second = app(GrantMonthlySmsCreditsAction::class)->handle($team);

    expect($first)->toBeTrue();
    expect($second)->toBeFalse();
    expect(
        SmsLedger::query()
            ->where('team_id', $team->id)
            ->where('reason', SmsLedger::REASON_MONTHLY_GRANT)
            ->count()
    )->toBe(1);
});

test('a call made in a genuinely new calendar month grants again', function () {
    $user = onboardedGrantTestUser();
    $team = $user->currentTeam()->fresh();
    // Force an active, non-trial subscription so travelling a month forward
    // doesn't lapse the trial and fall back to a Free (no-allotment) plan —
    // that would fail this test for an unrelated reason.
    $team->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::STARTER]);
    // Clear the signup flow's own lock before this test's first real call.
    test()->travel(31)->seconds();
    SmsLedger::query()->where('team_id', $team->id)->delete();

    $first = app(GrantMonthlySmsCreditsAction::class)->handle($team->fresh());

    // Travel to an absolute date in the next calendar month, rather than a
    // computed diffInSeconds() — clearer and avoids sign-confusion on a
    // forward-diff.
    test()->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addMinutes(5));
    $second = app(GrantMonthlySmsCreditsAction::class)->handle($team->fresh());

    expect($first)->toBeTrue();
    expect($second)->toBeTrue();
    expect(
        SmsLedger::query()
            ->where('team_id', $team->id)
            ->where('reason', SmsLedger::REASON_MONTHLY_GRANT)
            ->count()
    )->toBe(2);
});
