<?php

use App\Actions\Notifications\AutoTagAction;
use App\Actions\Notifications\NotifyMemberAction;
use App\Actions\Rules\DispatchRuleActionsAction;
use App\Models\Order;
use App\Models\Rule;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
