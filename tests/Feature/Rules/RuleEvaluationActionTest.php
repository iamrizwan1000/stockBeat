<?php

use App\Actions\Rules\RuleEvaluationAction;
use App\Models\Order;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('a matching enabled rule logs an execution', function () {
    $order = Order::factory()->create(['total' => 100]);
    $rule = Rule::factory()->create(['team_id' => $order->team_id, 'trigger' => Rule::TRIGGER_NEW_ORDER]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_NEW_ORDER, $order);

    expect($execution)->not->toBeNull();
    expect(RuleExecution::query()->count())->toBe(1);
});

test('a disabled rule never fires', function () {
    $order = Order::factory()->create();
    $rule = Rule::factory()->create(['team_id' => $order->team_id, 'enabled' => false]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_NEW_ORDER, $order);

    expect($execution)->toBeNull();
    expect(RuleExecution::query()->count())->toBe(0);
});

test('a rule whose conditions do not match does not fire', function () {
    $order = Order::factory()->create(['total' => 10]);
    $rule = Rule::factory()->create([
        'team_id' => $order->team_id,
        'conditions' => ['all' => [['field' => 'total', 'operator' => 'gt', 'value' => 500]]],
    ]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_NEW_ORDER, $order);

    expect($execution)->toBeNull();
});

test('the same rule never fires twice for the same order and trigger', function () {
    $order = Order::factory()->create();
    $rule = Rule::factory()->create(['team_id' => $order->team_id]);

    app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_NEW_ORDER, $order);
    $second = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_NEW_ORDER, $order);

    expect($second)->toBeNull();
    expect(RuleExecution::query()->count())->toBe(1);
});

test('cooldown_minutes blocks a subsequent firing across different orders', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create(['team_id' => $team->id, 'controls' => ['cooldown_minutes' => 60]]);

    $orderA = Order::factory()->create(['team_id' => $team->id]);
    $orderB = Order::factory()->create(['team_id' => $team->id]);

    $first = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_NEW_ORDER, $orderA);
    $second = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_NEW_ORDER, $orderB);

    expect($first)->not->toBeNull();
    expect($second)->toBeNull();

    Carbon::setTestNow(now()->addMinutes(61));
    $third = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_NEW_ORDER, $orderB);
    expect($third)->not->toBeNull();
    Carbon::setTestNow();
});

test('quiet hours suppress the action but still log the execution', function () {
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $order = Order::factory()->create(['team_id' => $team->id]);

    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'controls' => ['quiet_hours' => ['start' => '22:00', 'end' => '08:00']],
    ]);

    Carbon::setTestNow(Carbon::parse('2026-01-01 23:30:00', 'UTC'));

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_NEW_ORDER, $order);

    expect($execution)->not->toBeNull();
    expect($execution->actions_result[0]['status'])->toBe('skipped_quiet_hours');

    Carbon::setTestNow();
});

test('unfulfilled_after_x does not fire before the threshold has elapsed', function () {
    $order = Order::factory()->create([
        'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
        'placed_at' => now()->subHours(2),
    ]);
    $rule = Rule::factory()->create([
        'team_id' => $order->team_id,
        'trigger' => Rule::TRIGGER_UNFULFILLED_AFTER_X,
        'controls' => ['threshold_hours' => 24],
    ]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_UNFULFILLED_AFTER_X, $order);

    expect($execution)->toBeNull();
});

test('unfulfilled_after_x fires once the threshold has elapsed', function () {
    $order = Order::factory()->create([
        'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
        'placed_at' => now()->subHours(25),
    ]);
    $rule = Rule::factory()->create([
        'team_id' => $order->team_id,
        'trigger' => Rule::TRIGGER_UNFULFILLED_AFTER_X,
        'controls' => ['threshold_hours' => 24],
    ]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_UNFULFILLED_AFTER_X, $order);

    expect($execution)->not->toBeNull();
});

test('unfulfilled_after_x never fires once the order is fulfilled', function () {
    $order = Order::factory()->create([
        'fulfillment_status' => Order::FULFILLMENT_FULFILLED,
        'placed_at' => now()->subHours(48),
    ]);
    $rule = Rule::factory()->create([
        'team_id' => $order->team_id,
        'trigger' => Rule::TRIGGER_UNFULFILLED_AFTER_X,
        'controls' => ['threshold_hours' => 24],
    ]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_UNFULFILLED_AFTER_X, $order);

    expect($execution)->toBeNull();
});

test('ship_by_deadline fires once within the approach window', function () {
    $order = Order::factory()->create([
        'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
        'ship_by_at' => now()->addHours(3),
    ]);
    $rule = Rule::factory()->create([
        'team_id' => $order->team_id,
        'trigger' => Rule::TRIGGER_SHIP_BY_DEADLINE,
        'controls' => ['threshold_hours' => 6],
    ]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_SHIP_BY_DEADLINE, $order);

    expect($execution)->not->toBeNull();
});

test('ship_by_deadline does not fire while still far in the future', function () {
    $order = Order::factory()->create([
        'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
        'ship_by_at' => now()->addHours(48),
    ]);
    $rule = Rule::factory()->create([
        'team_id' => $order->team_id,
        'trigger' => Rule::TRIGGER_SHIP_BY_DEADLINE,
        'controls' => ['threshold_hours' => 6],
    ]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_SHIP_BY_DEADLINE, $order);

    expect($execution)->toBeNull();
});

test('ship_by_deadline does not fire once the deadline has already passed', function () {
    $order = Order::factory()->create([
        'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
        'ship_by_at' => now()->subHour(),
    ]);
    $rule = Rule::factory()->create([
        'team_id' => $order->team_id,
        'trigger' => Rule::TRIGGER_SHIP_BY_DEADLINE,
        'controls' => ['threshold_hours' => 6],
    ]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_SHIP_BY_DEADLINE, $order);

    expect($execution)->toBeNull();
});

test('order_spike does not fire below the configured count', function () {
    $team = Team::factory()->create();
    Order::factory()->count(3)->create(['team_id' => $team->id, 'placed_at' => now()]);
    $order = Order::factory()->create(['team_id' => $team->id, 'placed_at' => now()]);

    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_ORDER_SPIKE,
        'controls' => ['spike_count' => 10, 'spike_window_minutes' => 30],
    ]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_ORDER_SPIKE, $order);

    expect($execution)->toBeNull();
});

test('order_spike fires once the count within the window is reached', function () {
    $team = Team::factory()->create();
    Order::factory()->count(4)->create(['team_id' => $team->id, 'placed_at' => now()]);
    $order = Order::factory()->create(['team_id' => $team->id, 'placed_at' => now()]);

    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_ORDER_SPIKE,
        'controls' => ['spike_count' => 5, 'spike_window_minutes' => 30],
    ]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_ORDER_SPIKE, $order);

    expect($execution)->not->toBeNull();
});

test('order_spike ignores orders outside the window', function () {
    $team = Team::factory()->create();
    Order::factory()->count(4)->create(['team_id' => $team->id, 'placed_at' => now()->subHours(2)]);
    $order = Order::factory()->create(['team_id' => $team->id, 'placed_at' => now()]);

    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_ORDER_SPIKE,
        'controls' => ['spike_count' => 5, 'spike_window_minutes' => 30],
    ]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_ORDER_SPIKE, $order);

    expect($execution)->toBeNull();
});

test('refund_spike fires once the refunded count within the window is reached', function () {
    $team = Team::factory()->create();
    Order::factory()->count(4)->create(['team_id' => $team->id, 'status' => Order::STATUS_REFUNDED]);
    $order = Order::factory()->create(['team_id' => $team->id, 'status' => Order::STATUS_REFUNDED]);

    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_REFUND_SPIKE,
        'controls' => ['spike_count' => 5, 'spike_window_minutes' => 60],
    ]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_REFUND_SPIKE, $order);

    expect($execution)->not->toBeNull();
});

test('refund_spike does not count non-refunded orders', function () {
    $team = Team::factory()->create();
    Order::factory()->count(4)->create(['team_id' => $team->id, 'status' => Order::STATUS_NEW]);
    $order = Order::factory()->create(['team_id' => $team->id, 'status' => Order::STATUS_REFUNDED]);

    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_REFUND_SPIKE,
        'controls' => ['spike_count' => 5, 'spike_window_minutes' => 60],
    ]);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_REFUND_SPIKE, $order);

    expect($execution)->toBeNull();
});

test('an order-less trigger (digest) is not blocked by the hard per-order dedup', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_DIGEST]);

    $first = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_DIGEST, null);
    $second = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_DIGEST, null);

    expect($first)->not->toBeNull();
    expect($second)->not->toBeNull();
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(2);
});
