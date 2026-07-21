<?php

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Rule;
use App\Models\SmsLedger;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SupportMessage;
use App\Models\SupportThread;
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

test('the customer detail page includes subscription timeline, LTV, and abuse flags', function () {
    $admin = AdminUser::factory()->create();
    $owner = User::factory()->create(['base_currency' => 'USD']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => TeamMember::ROLE_OWNER]);
    Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_ACTIVE]);

    SubscriptionEvent::factory()->create([
        'team_id' => $team->id,
        'event_type' => 'INITIAL_PURCHASE',
        'price' => 9.99,
        'currency' => 'USD',
    ]);
    SmsLedger::factory()->create(['team_id' => $team->id, 'reason' => SmsLedger::REASON_SEND, 'delta' => -250, 'balance_after' => 0]);

    test()->actingAs($admin, 'admin')
        ->get("/admin/customers/{$owner->id}")
        ->assertInertia(fn ($page) => $page
            ->component('admin/customers/show')
            ->has('customer.subscription_timeline', 1)
            ->where('customer.subscription_timeline.0.event_type', 'INITIAL_PURCHASE')
            ->where('customer.ltv.total', 9.99)
            ->where('customer.ltv.currency', 'USD')
            ->where('customer.abuse_flags.high_sms_cost', true)
            ->where('customer.abuse_flags.trial_abuse_suspected', false)
        );
});

test('an admin can filter customers by a country they have actually shipped to', function () {
    $admin = AdminUser::factory()->create();

    $auUser = User::factory()->create(['name' => 'Ships to Australia']);
    $auTeam = Team::factory()->create(['owner_id' => $auUser->id]);
    $auConnection = StoreConnection::factory()->create(['team_id' => $auTeam->id]);
    Order::factory()->create(['team_id' => $auTeam->id, 'connection_id' => $auConnection->id, 'shipping_country' => 'AU']);

    $usUser = User::factory()->create(['name' => 'Ships to United States']);
    $usTeam = Team::factory()->create(['owner_id' => $usUser->id]);
    $usConnection = StoreConnection::factory()->create(['team_id' => $usTeam->id]);
    Order::factory()->create(['team_id' => $usTeam->id, 'connection_id' => $usConnection->id, 'shipping_country' => 'US']);

    $response = test()->actingAs($admin, 'admin')->get('/admin/customers?country=AU');

    $response->assertInertia(fn ($page) => $page
        ->component('admin/customers/index')
        ->has('customers.data', 1)
        ->where('customers.data.0.name', 'Ships to Australia')
    );
});

test('an admin can filter customers by an LTV range', function () {
    $admin = AdminUser::factory()->create();

    $highValueOwner = User::factory()->create(['name' => 'High Value', 'base_currency' => 'USD']);
    $highValueTeam = Team::factory()->create(['owner_id' => $highValueOwner->id]);
    SubscriptionEvent::factory()->create(['team_id' => $highValueTeam->id, 'price' => 500, 'currency' => 'USD']);

    $lowValueOwner = User::factory()->create(['name' => 'Low Value', 'base_currency' => 'USD']);
    $lowValueTeam = Team::factory()->create(['owner_id' => $lowValueOwner->id]);
    SubscriptionEvent::factory()->create(['team_id' => $lowValueTeam->id, 'price' => 5, 'currency' => 'USD']);

    $response = test()->actingAs($admin, 'admin')->get('/admin/customers?ltv_min=100');

    $response->assertInertia(fn ($page) => $page
        ->component('admin/customers/index')
        ->has('customers.data', 1)
        ->where('customers.data.0.name', 'High Value')
        ->where('customers.data.0.ltv', 500)
    );
});

test('the customer detail page surfaces real support thread history', function () {
    $admin = AdminUser::factory()->create();
    $user = User::factory()->create();
    $thread = SupportThread::factory()->create(['user_id' => $user->id, 'status' => SupportThread::STATUS_AWAITING_USER]);
    SupportMessage::factory()->create(['thread_id' => $thread->id, 'direction' => 'user', 'body' => 'My store stopped syncing.']);
    SupportMessage::factory()->create(['thread_id' => $thread->id, 'direction' => 'staff', 'body' => 'Looking into it now.']);

    $response = test()->actingAs($admin, 'admin')->get("/admin/customers/{$user->id}");

    $response->assertInertia(fn ($page) => $page
        ->component('admin/customers/show')
        ->where('customer.support_thread.id', $thread->id)
        ->where('customer.support_thread.status', SupportThread::STATUS_AWAITING_USER)
        ->has('customer.support_thread.recent_messages', 2)
        ->where('customer.support_thread.recent_messages.0.body', 'My store stopped syncing.')
    );
});

test('a customer with no support thread shows null, not an error', function () {
    $admin = AdminUser::factory()->create();
    $user = User::factory()->create();

    test()->actingAs($admin, 'admin')
        ->get("/admin/customers/{$user->id}")
        ->assertInertia(fn ($page) => $page->where('customer.support_thread', null));
});
