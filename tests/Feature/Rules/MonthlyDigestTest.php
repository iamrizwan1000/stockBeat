<?php

use App\Models\DailyStat;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Kreait\Firebase\Contract\Messaging;

uses(RefreshDatabase::class);

beforeEach(function () {
    test()->seed(PlanSeeder::class);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->andReturn([]);
    app()->instance(Messaging::class, $messaging);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a monthly digest rule only fires on its configured day of month', function () {
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_DIGEST,
        'enabled' => true,
        'controls' => ['digest_frequency' => 'monthly', 'digest_day_of_month' => 1],
    ]);

    Carbon::setTestNow(Carbon::parse('2026-02-15 07:00:00', 'UTC'));
    Artisan::call('rules:send-digests');
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-03-01 07:00:00', 'UTC'));
    Artisan::call('rules:send-digests');
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('a monthly digest does not refire again within the same month', function () {
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_DIGEST,
        'enabled' => true,
        'controls' => ['digest_frequency' => 'monthly', 'digest_day_of_month' => 1],
    ]);

    Carbon::setTestNow(Carbon::parse('2026-03-01 07:00:00', 'UTC'));
    Artisan::call('rules:send-digests');
    Artisan::call('rules:send-digests');
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);

    Carbon::setTestNow(Carbon::parse('2026-04-01 07:00:00', 'UTC'));
    Artisan::call('rules:send-digests');
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(2);
});

test('the monthly report covers the previous calendar month\'s real orders/revenue/best seller', function () {
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);

    // Real order_items data so the best-seller query has something to find —
    // digestBody's totals come from daily_stats, but best-seller/monthly
    // extras query orders/order_items directly.
    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'placed_at' => Carbon::parse('2026-02-15')]);
    OrderItem::factory()->create(['order_id' => $order->id, 'title' => 'Blue Widget', 'qty' => 2, 'price' => 50]);

    DailyStat::query()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'date' => Carbon::parse('2026-02-15'),
        'orders_count' => 1,
        'revenue' => 100,
        'revenue_base' => 100,
        'aov' => 100,
        'refunds' => 0,
    ]);

    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_DIGEST,
        'enabled' => true,
        'controls' => ['digest_frequency' => 'monthly', 'digest_day_of_month' => 1],
    ]);

    Carbon::setTestNow(Carbon::parse('2026-03-01 07:00:00', 'UTC'));
    Artisan::call('rules:send-digests');

    $execution = RuleExecution::query()->where('rule_id', $rule->id)->firstOrFail();
    expect($execution->actions_result)->not->toBeEmpty();
});

test('a Starter team\'s monthly digest stays plain; the same content on a full-analytics plan gets the per-channel/top-products breakdown', function () {
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'platform' => StoreConnection::PLATFORM_WOO]);

    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'platform' => StoreConnection::PLATFORM_WOO, 'placed_at' => Carbon::parse('2026-02-10'), 'total_base_currency' => 100, 'is_test' => false]);
    OrderItem::factory()->create(['order_id' => $order->id, 'title' => 'Blue Widget', 'qty' => 1, 'price' => 100]);

    DailyStat::query()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'date' => Carbon::parse('2026-02-10'),
        'orders_count' => 1,
        'revenue' => 100,
        'revenue_base' => 100,
        'aov' => 100,
        'refunds' => 0,
    ]);

    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_DIGEST,
        'enabled' => true,
        'actions' => [['type' => 'push']],
        'controls' => ['digest_frequency' => 'monthly', 'digest_day_of_month' => 1],
    ]);

    // This team was created directly via the factory (no onboarding flow),
    // so it has no subscription row at all yet — create one on Starter
    // (7d analytics, not full) to test the non-enriched path first.
    $subscription = Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::STARTER]);

    Carbon::setTestNow(Carbon::parse('2026-03-01 07:00:00', 'UTC'));
    Artisan::call('rules:send-digests');

    // Digest actions push to the rule's *creator*, not necessarily the team
    // owner (`DispatchRuleActionsAction` uses `$rule->creator`) — query
    // without an owner filter since this test's only notification recipient
    // is whichever user the rule factory assigned as `created_by`.
    $starterBody = Notification::query()->latest()->value('body');
    expect($starterBody)->toContain('February 2026: 1 orders, $100.00.');
    expect($starterBody)->not->toContain('By channel:');
    expect($starterBody)->not->toContain('Top products:');

    // Same team, now on a full-analytics plan — re-run for the next month.
    $subscription->update(['plan_key' => Plan::PRO]);

    $marchOrder = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'platform' => StoreConnection::PLATFORM_WOO, 'placed_at' => Carbon::parse('2026-03-10'), 'total_base_currency' => 50, 'is_test' => false]);
    OrderItem::factory()->create(['order_id' => $marchOrder->id, 'title' => 'Red Gadget', 'qty' => 1, 'price' => 50]);
    DailyStat::query()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'date' => Carbon::parse('2026-03-10'),
        'orders_count' => 1,
        'revenue' => 50,
        'revenue_base' => 50,
        'aov' => 50,
        'refunds' => 0,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-04-01 07:00:00', 'UTC'));
    Artisan::call('rules:send-digests');

    $proBody = Notification::query()->latest()->value('body');
    expect($proBody)->toContain('By channel:');
    expect($proBody)->toContain('Top products:');
});
