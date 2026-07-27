<?php

use App\Actions\Admin\ComputeDashboardKpisAction;
use App\Actions\Admin\ComputeFeatureAdoptionAction;
use App\Actions\Admin\ComputePaywallHitsSnapshotAction;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\FeatureUsageEvent;
use App\Models\OrderEvent;
use App\Models\Payout;
use App\Models\PaywallHit;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Review;
use App\Models\Rule;
use App\Models\SavedOrderFilter;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the dashboard requires admin authentication', function () {
    test()->get('/admin')->assertRedirect('/admin/login');
});

test('an authenticated admin can view the dashboard', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')->get('/admin')->assertOk();
});

test('dashboard KPIs reflect real data', function () {
    $owner = User::factory()->create(['created_at' => now()]);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_TRIAL, 'trial_ends_at' => now()->addDays(3)]);
    StoreConnection::factory()->create(['team_id' => $team->id, 'platform' => 'woo']);
    Device::factory()->create(['user_id' => $owner->id]);
    Rule::factory()->create(['team_id' => $team->id, 'created_by' => $owner->id]);

    $kpis = app(ComputeDashboardKpisAction::class)->handle();

    expect($kpis['signups']['today'])->toBe(1);
    expect($kpis['trials']['active'])->toBe(1);
    expect($kpis['platforms'])->toContain(['platform' => 'woo', 'count' => 1]);
    expect($kpis['funnel']['signups'])->toBe(1);
    expect($kpis['funnel']['store_connected'])->toBe(1);
    expect($kpis['funnel']['push_enabled'])->toBe(1);
    expect($kpis['funnel']['rule_created'])->toBe(1);
});

test('feature adoption reflects real data', function () {
    $team = Team::factory()->create();

    SavedOrderFilter::factory()->create(['team_id' => $team->id]);
    Payout::factory()->create(['team_id' => $team->id]);

    $repliedReview = Review::factory()->create(['team_id' => $team->id, 'replied_at' => now()]);
    Review::factory()->create(['team_id' => $team->id, 'replied_at' => null]);

    Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_POSITIVE_REVIEW]);
    Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_STALE_INVENTORY]);
    Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_DIGEST,
        'controls' => ['digest_frequency' => 'monthly', 'digest_day_of_month' => 1],
    ]);
    Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_DIGEST,
        'controls' => ['digest_frequency' => 'weekly'],
    ]);
    Rule::factory()->create(['team_id' => $team->id, 'priority' => 'critical']);

    FeatureUsageEvent::log($team, FeatureUsageEvent::FEATURE_INVOICE_GENERATED);
    FeatureUsageEvent::log($team, FeatureUsageEvent::FEATURE_BULK_TAG_USED);

    OrderEvent::factory()->create(['type' => OrderEvent::TYPE_FULFILLED]);
    OrderEvent::factory()->create(['type' => OrderEvent::TYPE_FULFILLED]);
    OrderEvent::factory()->create(['type' => OrderEvent::TYPE_TAGS_UPDATED]);

    $adoption = app(ComputeFeatureAdoptionAction::class)->handle();

    expect($adoption['saved_filters'])->toBe(['count' => 1, 'teams' => 1]);
    expect($adoption['payouts'])->toBe(['count' => 1, 'teams' => 1]);
    expect($adoption['reviews']['total'])->toBe(2);
    expect($adoption['reviews']['replied'])->toBe(1);
    expect($adoption['reviews']['reply_rate_pct'])->toBe(50.0);
    expect($adoption['rules']['positive_review'])->toBe(1);
    expect($adoption['rules']['stale_inventory'])->toBe(1);
    expect($adoption['rules']['monthly_digest'])->toBe(1);
    expect($adoption['rules']['priority_critical'])->toBe(1);
    expect($adoption['usage_events'][FeatureUsageEvent::FEATURE_INVOICE_GENERATED])->toBe(['count' => 1, 'teams' => 1]);
    expect($adoption['usage_events'][FeatureUsageEvent::FEATURE_BULK_TAG_USED])->toBe(['count' => 1, 'teams' => 1]);
    expect($adoption['quick_actions']['fulfilled'])->toBe(2);
    expect($adoption['quick_actions']['tags_updated'])->toBe(1);

    expect($repliedReview->replied_at)->not->toBeNull();
});

test('the admin dashboard page includes feature adoption data', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('featureAdoption'));
});

test('paywall hits snapshot reflects real data', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    PaywallHit::log($teamA, PlanLimit::BULK_ACTIONS_ENABLED);
    PaywallHit::log($teamA, PlanLimit::BULK_ACTIONS_ENABLED);
    PaywallHit::log($teamA, PlanLimit::MAX_STORES);
    PaywallHit::log($teamB, PlanLimit::BULK_ACTIONS_ENABLED);

    PaywallHit::query()->create([
        'team_id' => $teamA->id,
        'limit_key' => PlanLimit::MAX_STORES,
        'plan_key' => Plan::FREE,
        'occurred_at' => now()->subDays(45),
    ]);

    $snapshot = app(ComputePaywallHitsSnapshotAction::class)->handle();

    expect($snapshot['total'])->toBe(5);
    expect($snapshot['last_30_days'])->toBe(4);

    $byLimitKey = collect($snapshot['by_limit_key'])->keyBy('limit_key');
    expect($byLimitKey[PlanLimit::BULK_ACTIONS_ENABLED])->toBe([
        'limit_key' => PlanLimit::BULK_ACTIONS_ENABLED,
        'count' => 3,
        'teams' => 2,
    ]);
    expect($byLimitKey[PlanLimit::MAX_STORES]['count'])->toBe(2);

    $topTeams = collect($snapshot['top_teams'])->keyBy('team_id');
    expect($topTeams[$teamA->id]['hits'])->toBe(3);
    expect($topTeams[$teamA->id]['team_name'])->toBe($teamA->name);
    expect($topTeams[$teamB->id]['hits'])->toBe(1);
});

test('the admin dashboard page includes paywall hits data', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('paywallHits'));
});
