<?php

use App\Actions\Notifications\AutoTagAction;
use App\Actions\Notifications\NotifyMemberAction;
use App\Actions\Rules\DispatchRuleActionsAction;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Rule;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;

uses(RefreshDatabase::class);

beforeEach(function () {
    test()->seed(PlanSeeder::class);
});

test('auto_tag appends a tag to the order and is idempotent', function () {
    $order = Order::factory()->create(['tags' => []]);

    $status = app(AutoTagAction::class)->handle($order, 'vip');
    expect($status)->toBe('tagged');
    expect($order->fresh()->tags)->toBe(['vip']);

    $status = app(AutoTagAction::class)->handle($order->fresh(), 'vip');
    expect($status)->toBe('already_tagged');
    expect($order->fresh()->tags)->toBe(['vip']);
});

test('notify_member refuses a user who is not on the team', function () {
    $team = Team::factory()->create();
    $outsider = User::factory()->create();

    $status = app(NotifyMemberAction::class)->handle($team, $outsider->id, 'Title', 'Body');

    expect($status)->toBe('not_a_team_member');
});

test('notify_member targets a real team member', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => TeamMember::ROLE_MANAGER]);

    $status = app(NotifyMemberAction::class)->handle($team, $member->id, 'Title', 'Body');

    expect($status)->toBe('no_devices');
});

test('notify_member is muted when the given store connection is muted', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => TeamMember::ROLE_MANAGER]);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'notifications_muted' => true]);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('send');
    app()->instance(Messaging::class, $messaging);

    $status = app(NotifyMemberAction::class)->handle($team, $member->id, 'Title', 'Body', connection: $connection);

    expect($status)->toBe('muted_by_store');
});

test('DispatchRuleActionsAction routes each action type and reports per-action outcomes', function () {
    $order = Order::factory()->create(['order_number' => '#42', 'total' => 99.5, 'currency' => 'USD']);
    $rule = Rule::factory()->create(['team_id' => $order->team_id, 'name' => 'Big order alert']);

    $actions = [
        ['type' => 'push'],
        ['type' => 'auto_tag', 'tag' => 'flagged'],
        ['type' => 'something_unknown'],
    ];

    $results = app(DispatchRuleActionsAction::class)->handle($rule, $actions, $order);

    expect($results[0])->toMatchArray(['type' => 'push', 'status' => 'no_devices']);
    expect($results[1])->toMatchArray(['type' => 'auto_tag', 'status' => 'tagged']);
    expect($results[2])->toMatchArray(['type' => 'something_unknown', 'status' => 'unknown_action_type']);
    expect($order->fresh()->tags)->toBe(['flagged']);
});

test('DispatchRuleActionsAction resolves the order\'s store connection and mutes every channel when it is muted', function () {
    $connection = StoreConnection::factory()->create(['notifications_muted' => true]);
    $order = Order::factory()->create(['connection_id' => $connection->id, 'total' => 99.5]);
    $rule = Rule::factory()->create(['team_id' => $order->team_id, 'name' => 'Big order alert']);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('send');
    app()->instance(Messaging::class, $messaging);

    $actions = [
        ['type' => 'push'],
        ['type' => 'email'],
        ['type' => 'sms'],
    ];

    $results = app(DispatchRuleActionsAction::class)->handle($rule, $actions, $order);

    expect($results[0])->toMatchArray(['type' => 'push', 'status' => 'muted_by_store']);
    expect($results[1])->toMatchArray(['type' => 'email', 'status' => 'muted_by_store']);
    expect($results[2])->toMatchArray(['type' => 'sms', 'status' => 'muted_by_store']);
});

test('DispatchRuleActionsAction is unaffected when the order\'s store connection is not muted', function () {
    $connection = StoreConnection::factory()->create(['notifications_muted' => false]);
    $order = Order::factory()->create(['connection_id' => $connection->id, 'total' => 99.5]);
    $rule = Rule::factory()->create(['team_id' => $order->team_id, 'name' => 'Big order alert']);

    $results = app(DispatchRuleActionsAction::class)->handle($rule, [['type' => 'push']], $order);

    expect($results[0])->toMatchArray(['type' => 'push', 'status' => 'no_devices']);
});

test('DispatchRuleActionsAction resolves connection_id from context for order-less triggers', function () {
    $connection = StoreConnection::factory()->create(['notifications_muted' => true]);
    $rule = Rule::factory()->create(['trigger' => Rule::TRIGGER_LOW_STOCK, 'name' => 'Low stock alert']);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('send');
    app()->instance(Messaging::class, $messaging);

    $results = app(DispatchRuleActionsAction::class)->handle(
        $rule,
        [['type' => 'push']],
        null,
        ['title' => 'Widget', 'sku' => 'W-1', 'stock_quantity' => 2, 'connection_id' => $connection->id],
    );

    expect($results[0])->toMatchArray(['type' => 'push', 'status' => 'muted_by_store']);
});

test('DispatchRuleActionsAction stamps platform and trigger onto the push and email Notification rows', function () {
    $connection = StoreConnection::factory()->create(['platform' => StoreConnection::PLATFORM_EBAY]);
    $order = Order::factory()->create(['connection_id' => $connection->id, 'total' => 250]);
    $rule = Rule::factory()->create(['team_id' => $order->team_id, 'trigger' => Rule::TRIGGER_HIGH_VALUE_ORDER, 'name' => 'Big order alert']);

    app(DispatchRuleActionsAction::class)->handle($rule, [['type' => 'push'], ['type' => 'email']], $order);

    $push = Notification::query()->where('type', Notification::TYPE_RULE_PUSH)->firstOrFail();
    expect($push->data)->toMatchArray(['platform' => 'ebay', 'trigger' => 'high_value_order', 'order_id' => (string) $order->id]);

    $email = Notification::query()->where('type', Notification::TYPE_RULE_EMAIL)->firstOrFail();
    expect($email->data)->toMatchArray(['platform' => 'ebay', 'trigger' => 'high_value_order']);
});

test('an order-less trigger with no resolvable store still stamps trigger but omits platform', function () {
    $rule = Rule::factory()->create(['trigger' => Rule::TRIGGER_AI_INSIGHT, 'name' => 'AI insight rule']);

    app(DispatchRuleActionsAction::class)->handle($rule, [['type' => 'push']], null, ['insight' => 'Revenue is down 30% today.']);

    $push = Notification::query()->where('type', Notification::TYPE_RULE_PUSH)->firstOrFail();
    expect($push->data)->toBe(['trigger' => 'ai_insight']);
});
