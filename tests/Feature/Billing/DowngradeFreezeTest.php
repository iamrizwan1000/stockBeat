<?php

use App\Actions\Billing\ApplyDowngradeFreezeAction;
use App\Actions\Billing\ReverseDowngradeFreezeAction;
use App\Models\Rule;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function teamWithResources(int $storeCount = 3, int $ruleCount = 2, int $memberCount = 3): Team
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => TeamMember::ROLE_OWNER, 'created_at' => now()->subDays(10)]);

    for ($i = 0; $i < $storeCount; $i++) {
        StoreConnection::factory()->create(['team_id' => $team->id, 'status' => StoreConnection::STATUS_ACTIVE, 'created_at' => now()->subDays(10 - $i)]);
    }

    for ($i = 0; $i < $ruleCount; $i++) {
        Rule::factory()->create(['team_id' => $team->id, 'enabled' => true, 'created_by' => $owner->id, 'created_at' => now()->subDays(10 - $i)]);
    }

    for ($i = 1; $i < $memberCount; $i++) {
        $member = User::factory()->create();
        TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => TeamMember::ROLE_AGENT, 'created_at' => now()->subDays(10 - $i)]);
    }

    return $team;
}

test('freezing pauses every store but the oldest one', function () {
    $team = teamWithResources(storeCount: 3);

    app(ApplyDowngradeFreezeAction::class)->handle($team);

    $connections = StoreConnection::query()->where('team_id', $team->id)->orderBy('created_at')->get();
    expect($connections[0]->status)->toBe(StoreConnection::STATUS_ACTIVE);
    expect($connections[1]->status)->toBe(StoreConnection::STATUS_PAUSED);
    expect($connections[2]->status)->toBe(StoreConnection::STATUS_PAUSED);
    expect($connections[1]->paused_at)->not->toBeNull();
});

test('freezing disables every enabled rule and tracks it as auto-disabled', function () {
    $team = teamWithResources(ruleCount: 2);

    app(ApplyDowngradeFreezeAction::class)->handle($team);

    $rules = Rule::query()->where('team_id', $team->id)->get();
    expect($rules->every(fn (Rule $r) => ! $r->enabled && $r->auto_disabled_at !== null))->toBeTrue();
});

test('freezing does not touch a rule the user had already disabled themselves', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $manuallyDisabled = Rule::factory()->create(['team_id' => $team->id, 'enabled' => false, 'created_by' => $owner->id]);

    app(ApplyDowngradeFreezeAction::class)->handle($team);

    expect($manuallyDisabled->fresh()->auto_disabled_at)->toBeNull();
});

test('freezing suspends every member beyond the free plan seat limit (1 — the owner)', function () {
    $team = teamWithResources(memberCount: 3);

    app(ApplyDowngradeFreezeAction::class)->handle($team);

    $members = TeamMember::query()->where('team_id', $team->id)->orderBy('created_at')->get();
    expect($members[0]->suspended_at)->toBeNull(); // owner — the free plan's one seat
    expect($members[1]->suspended_at)->not->toBeNull();
    expect($members[2]->suspended_at)->not->toBeNull();
});

test('freezing twice is a no-op the second time', function () {
    $team = teamWithResources();

    app(ApplyDowngradeFreezeAction::class)->handle($team);
    $firstPausedAt = StoreConnection::query()->where('team_id', $team->id)->where('status', StoreConnection::STATUS_PAUSED)->first()->paused_at;

    app(ApplyDowngradeFreezeAction::class)->handle($team);
    $secondPausedAt = StoreConnection::query()->where('team_id', $team->id)->where('status', StoreConnection::STATUS_PAUSED)->first()->paused_at;

    expect($firstPausedAt->eq($secondPausedAt))->toBeTrue();
});

test('unfreezing restores stores, rules, and members up to the new plan limits, oldest first', function () {
    $team = teamWithResources(storeCount: 3, ruleCount: 3, memberCount: 3);
    app(ApplyDowngradeFreezeAction::class)->handle($team);

    // Upgrade to Starter: 3 stores, 5 rules, 1 seat.
    Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_ACTIVE, 'plan_key' => 'starter']);

    app(ReverseDowngradeFreezeAction::class)->handle($team);

    $connections = StoreConnection::query()->where('team_id', $team->id)->get();
    expect($connections->where('status', StoreConnection::STATUS_ACTIVE)->count())->toBe(3);

    $rules = Rule::query()->where('team_id', $team->id)->get();
    expect($rules->where('enabled', true)->count())->toBe(3);

    $members = TeamMember::query()->where('team_id', $team->id)->get();
    expect($members->whereNull('suspended_at')->count())->toBe(1);
});

test('unfreezing to a lower tier than before the freeze only restores what fits', function () {
    $team = teamWithResources(storeCount: 5, ruleCount: 1, memberCount: 1);
    app(ApplyDowngradeFreezeAction::class)->handle($team);

    // Upgrade to Starter (max_stores: 3) — 5 stores were frozen, only room for 2 more beyond the 1 kept active.
    Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_ACTIVE, 'plan_key' => 'starter']);

    app(ReverseDowngradeFreezeAction::class)->handle($team);

    $connections = StoreConnection::query()->where('team_id', $team->id)->get();
    expect($connections->where('status', StoreConnection::STATUS_ACTIVE)->count())->toBe(3);
    expect($connections->where('status', StoreConnection::STATUS_PAUSED)->count())->toBe(2);
});
