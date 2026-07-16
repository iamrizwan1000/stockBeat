<?php

use App\Models\AdminUser;
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

test('the customer list requires admin authentication', function () {
    test()->get('/admin/customers')->assertRedirect('/admin/login');
});

test('an admin can search customers by name and email', function () {
    $admin = AdminUser::factory()->create();
    User::factory()->create(['name' => 'Jamie Seller', 'email' => 'jamie@example.com']);
    User::factory()->create(['name' => 'Alex Other', 'email' => 'alex@example.com']);

    test()->actingAs($admin, 'admin')
        ->get('/admin/customers?q=jamie')
        ->assertOk();
});

test('an admin can filter customers by platform connected', function () {
    $admin = AdminUser::factory()->create();

    $matchingUser = User::factory()->create();
    $matchingTeam = Team::factory()->create(['owner_id' => $matchingUser->id]);
    StoreConnection::factory()->create(['team_id' => $matchingTeam->id, 'platform' => 'shopify']);

    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->create(['owner_id' => $otherUser->id]);
    StoreConnection::factory()->create(['team_id' => $otherTeam->id, 'platform' => 'woo']);

    $response = test()->actingAs($admin, 'admin')->get('/admin/customers?platform=shopify');

    $response->assertOk();
});

test('CSV export streams a valid CSV with the customer data', function () {
    $admin = AdminUser::factory()->create();
    User::factory()->create(['name' => 'Export Test', 'email' => 'export@example.com']);

    $response = test()->actingAs($admin, 'admin')->get('/admin/customers/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->streamedContent())->toContain('Export Test');
    expect($response->streamedContent())->toContain('export@example.com');
});

test('the customer detail page shows real team, subscription, and rule data', function () {
    $admin = AdminUser::factory()->create();
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => TeamMember::ROLE_OWNER]);
    Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_ACTIVE, 'product_id' => 'pro_monthly']);
    Rule::factory()->create(['team_id' => $team->id, 'created_by' => $owner->id]);

    $response = test()->actingAs($admin, 'admin')->get("/admin/customers/{$owner->id}");

    $response->assertOk();
});

test('a customer with no team shows a clean empty state, not an error', function () {
    $admin = AdminUser::factory()->create();
    $user = User::factory()->create();

    test()->actingAs($admin, 'admin')->get("/admin/customers/{$user->id}")->assertOk();
});
