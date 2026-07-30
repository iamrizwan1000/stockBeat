<?php

use App\Actions\Admin\ExtendTrialAction;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\AiUsageLedger;
use App\Models\EmailUsageLedger;
use App\Models\Plan;
use App\Models\SmsLedger;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function customerWithTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id]);

    return [$user, $team];
}

test('a readonly admin cannot perform write actions', function () {
    $admin = AdminUser::factory()->readonly()->create();
    [$user] = customerWithTeam();

    test()->actingAs($admin, 'admin')
        ->post("/admin/customers/{$user->id}/force-logout")
        ->assertForbidden();
});

test('extending a trial updates the subscription and logs the action', function () {
    $admin = AdminUser::factory()->create();
    [$user, $team] = customerWithTeam();
    Subscription::factory()->create(['team_id' => $team->id, 'trial_ends_at' => now()->addDay()]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/customers/{$user->id}/extend-trial", ['days' => 7])
        ->assertRedirect();

    $team->refresh();
    expect($team->subscription->trial_ends_at->diffInDays(now()->addDays(8)))->toBeLessThan(1);
    expect(AdminAuditLog::query()->where('action', 'customer.extend_trial')->where('admin_id', $admin->id)->exists())->toBeTrue();
});

test('extending a trial for a team with no subscription row yet grants full premium entitlements', function () {
    $admin = AdminUser::factory()->create();
    [$user, $team] = customerWithTeam();

    expect($team->subscription)->toBeNull();

    test()->actingAs($admin, 'admin')
        ->post("/admin/customers/{$user->id}/extend-trial", ['days' => 7])
        ->assertRedirect();

    $team->refresh();
    expect($team->subscription->plan_key)->toBe(Plan::PREMIUM);
    expect($team->subscription->effectivePlanKey())->toBe(Plan::PREMIUM);
});

test('extending a trial for a team with a lapsed paid subscription resets it to premium, not the old tier', function () {
    $admin = AdminUser::factory()->create();
    [$user, $team] = customerWithTeam();
    Subscription::factory()->create([
        'team_id' => $team->id,
        'status' => Subscription::STATUS_EXPIRED,
        'plan_key' => Plan::STARTER,
        'trial_ends_at' => now()->subDays(30),
    ]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/customers/{$user->id}/extend-trial", ['days' => 7])
        ->assertRedirect();

    $team->refresh();
    expect($team->subscription->status)->toBe(Subscription::STATUS_TRIAL);
    expect($team->subscription->plan_key)->toBe(Plan::PREMIUM);
});

test('granting complimentary pro sets an active comp subscription', function () {
    $admin = AdminUser::factory()->create();
    [$user, $team] = customerWithTeam();

    test()->actingAs($admin, 'admin')
        ->post("/admin/customers/{$user->id}/grant-pro", ['days' => 30])
        ->assertRedirect();

    $team->refresh();
    expect($team->subscription->status)->toBe(Subscription::STATUS_ACTIVE);
    expect($team->subscription->provider)->toBe('comp');
});

test('double-clicking extend trial does not hand out twice the days', function () {
    $admin = AdminUser::factory()->create();
    [, $team] = customerWithTeam();
    Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_TRIAL, 'trial_ends_at' => now()->addDay()]);

    $first = app(ExtendTrialAction::class)->handle($admin, $team->fresh(), 7);
    $second = app(ExtendTrialAction::class)->handle($admin, $team->fresh(), 7);

    expect($first)->not->toBeNull();
    expect($second)->toBeNull();
    // The extension is cumulative off a still-future trial_ends_at, so an
    // unguarded double-click landed on ~15 days (1 + 7 + 7), not 8.
    expect($team->fresh()->subscription->trial_ends_at->diffInDays(now()->addDays(8)))->toBeLessThan(1);
});

test('a genuinely intentional second trial extension after the guard window still works', function () {
    $admin = AdminUser::factory()->create();
    [, $team] = customerWithTeam();
    Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_TRIAL, 'trial_ends_at' => now()->addDay()]);

    app(ExtendTrialAction::class)->handle($admin, $team->fresh(), 7);
    test()->travel(11)->seconds();
    $second = app(ExtendTrialAction::class)->handle($admin, $team->fresh(), 7);

    expect($second)->not->toBeNull();
    expect($team->fresh()->subscription->trial_ends_at->diffInDays(now()->addDays(15)))->toBeLessThan(1);
});

test('double-clicking the extend trial endpoint flashes a wait-a-moment status', function () {
    $admin = AdminUser::factory()->create();
    [$user, $team] = customerWithTeam();
    Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_TRIAL, 'trial_ends_at' => now()->addDay()]);

    $first = test()->actingAs($admin, 'admin')->post("/admin/customers/{$user->id}/extend-trial", ['days' => 7]);
    $second = test()->actingAs($admin, 'admin')->post("/admin/customers/{$user->id}/extend-trial", ['days' => 7]);

    $first->assertRedirect();
    $second->assertRedirect()->assertSessionHas('status', fn ($status) => str_contains($status, 'wait a moment'));
    expect($team->fresh()->subscription->trial_ends_at->diffInDays(now()->addDays(8)))->toBeLessThan(1);
});

test('granting bonus sms credits increases the ledger balance', function () {
    $admin = AdminUser::factory()->create();
    [$user, $team] = customerWithTeam();
    SmsLedger::factory()->create(['team_id' => $team->id, 'delta' => 50, 'balance_after' => 50]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/customers/{$user->id}/grant-sms-credits", ['credits' => 100])
        ->assertRedirect();

    $latest = SmsLedger::query()->where('team_id', $team->id)->latest('id')->first();
    expect($latest->balance_after)->toBe(150);
});

test('double-clicking grant bonus sms credits only grants once', function () {
    $admin = AdminUser::factory()->create();
    [$user, $team] = customerWithTeam();

    $first = test()->actingAs($admin, 'admin')->post("/admin/customers/{$user->id}/grant-sms-credits", ['credits' => 100]);
    $second = test()->actingAs($admin, 'admin')->post("/admin/customers/{$user->id}/grant-sms-credits", ['credits' => 100]);

    $first->assertRedirect();
    $second->assertRedirect()->assertSessionHas('status', fn ($status) => str_contains($status, 'wait a moment'));
    expect(SmsLedger::query()->where('team_id', $team->id)->count())->toBe(1);
    expect(SmsLedger::query()->where('team_id', $team->id)->latest('id')->first()->balance_after)->toBe(100);
});

test('granting bonus AI question credits raises the current month\'s effective quota', function () {
    $admin = AdminUser::factory()->create();
    [$user, $team] = customerWithTeam();

    test()->actingAs($admin, 'admin')
        ->post("/admin/customers/{$user->id}/grant-ai-credits", ['credits' => 20])
        ->assertRedirect();

    expect(AiUsageLedger::bonusGrantedThisMonth($team->id))->toBe(20);
    expect(AiUsageLedger::effectiveMonthlyLimit($team->id, 30))->toBe(50);
    expect(AdminAuditLog::query()->where('action', 'customer.grant_bonus_ai_credits')->where('target_id', $team->id)->exists())->toBeTrue();
});

test('double-clicking grant bonus AI credits only grants once', function () {
    $admin = AdminUser::factory()->create();
    [$user, $team] = customerWithTeam();

    test()->actingAs($admin, 'admin')->post("/admin/customers/{$user->id}/grant-ai-credits", ['credits' => 20]);
    test()->actingAs($admin, 'admin')->post("/admin/customers/{$user->id}/grant-ai-credits", ['credits' => 20]);

    expect(AiUsageLedger::bonusGrantedThisMonth($team->id))->toBe(20);
});

test('double-clicking grant bonus email credits only grants once', function () {
    $admin = AdminUser::factory()->create();
    [$user, $team] = customerWithTeam();

    test()->actingAs($admin, 'admin')->post("/admin/customers/{$user->id}/grant-email-credits", ['credits' => 50]);
    test()->actingAs($admin, 'admin')->post("/admin/customers/{$user->id}/grant-email-credits", ['credits' => 50]);

    expect(EmailUsageLedger::bonusGrantedThisMonth($team->id))->toBe(50);
});

test('granting bonus email credits raises the current month\'s effective quota', function () {
    $admin = AdminUser::factory()->create();
    [$user, $team] = customerWithTeam();

    test()->actingAs($admin, 'admin')
        ->post("/admin/customers/{$user->id}/grant-email-credits", ['credits' => 50])
        ->assertRedirect();

    expect(EmailUsageLedger::bonusGrantedThisMonth($team->id))->toBe(50);
    expect(EmailUsageLedger::effectiveMonthlyLimit($team->id, 25))->toBe(75);
    expect(AdminAuditLog::query()->where('action', 'customer.grant_bonus_email_credits')->where('target_id', $team->id)->exists())->toBeTrue();
});

test('force logout revokes all sanctum tokens', function () {
    $admin = AdminUser::factory()->create();
    [$user] = customerWithTeam();
    $user->createToken('device-1');
    $user->createToken('device-2');

    test()->actingAs($admin, 'admin')
        ->post("/admin/customers/{$user->id}/force-logout")
        ->assertRedirect();

    expect($user->tokens()->count())->toBe(0);
});

test('suspending an account sets suspended_at and revokes tokens', function () {
    $admin = AdminUser::factory()->create();
    [$user] = customerWithTeam();
    $user->createToken('device-1');

    test()->actingAs($admin, 'admin')
        ->post("/admin/customers/{$user->id}/suspend")
        ->assertRedirect();

    expect($user->fresh()->suspended_at)->not->toBeNull();
    expect($user->tokens()->count())->toBe(0);
});

test('unsuspending clears suspended_at', function () {
    $admin = AdminUser::factory()->create();
    [$user] = customerWithTeam();
    $user->update(['suspended_at' => now()]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/customers/{$user->id}/unsuspend")
        ->assertRedirect();

    expect($user->fresh()->suspended_at)->toBeNull();
});

test('a suspended user is rejected by the mobile API', function () {
    $user = User::factory()->create(['suspended_at' => now()]);
    Sanctum::actingAs($user);

    test()->getJson('/api/v1/me')->assertForbidden();
});
