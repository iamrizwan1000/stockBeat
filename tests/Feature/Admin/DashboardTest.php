<?php

use App\Actions\Admin\ComputeDashboardKpisAction;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\Rule;
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
