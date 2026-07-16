<?php

use App\Models\Order;
use App\Models\Team;
use App\Support\Rules\ConditionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function evaluator(): ConditionEvaluator
{
    return new ConditionEvaluator;
}

test('null or empty conditions always match', function () {
    $order = Order::factory()->create();

    expect(evaluator()->evaluate(null, $order))->toBeTrue();
    expect(evaluator()->evaluate([], $order))->toBeTrue();
});

test('all conditions must match (AND)', function () {
    $order = Order::factory()->create(['total' => 50, 'platform' => 'woo']);

    $conditions = ['all' => [
        ['field' => 'total', 'operator' => 'gt', 'value' => 10],
        ['field' => 'channel', 'operator' => 'eq', 'value' => 'woo'],
    ]];
    expect(evaluator()->evaluate($conditions, $order))->toBeTrue();

    $conditions['all'][1]['value'] = 'shopify';
    expect(evaluator()->evaluate($conditions, $order))->toBeFalse();
});

test('any conditions require at least one match (OR)', function () {
    $order = Order::factory()->create(['tags' => ['vip']]);

    expect(evaluator()->evaluate(['any' => [
        ['field' => 'tag', 'value' => 'vip'],
        ['field' => 'tag', 'value' => 'wholesale'],
    ]], $order))->toBeTrue();

    expect(evaluator()->evaluate(['any' => [
        ['field' => 'tag', 'value' => 'wholesale'],
    ]], $order))->toBeFalse();
});

test('total supports gt, lt, and between', function () {
    $order = Order::factory()->create(['total' => 100]);

    expect(evaluator()->evaluate(['all' => [['field' => 'total', 'operator' => 'gt', 'value' => 50]]], $order))->toBeTrue();
    expect(evaluator()->evaluate(['all' => [['field' => 'total', 'operator' => 'lt', 'value' => 50]]], $order))->toBeFalse();
    expect(evaluator()->evaluate(['all' => [['field' => 'total', 'operator' => 'between', 'value' => [10, 200]]]], $order))->toBeTrue();
    expect(evaluator()->evaluate(['all' => [['field' => 'total', 'operator' => 'between', 'value' => [200, 300]]]], $order))->toBeFalse();
});

test('sku and product conditions search order items', function () {
    $order = Order::factory()->create();
    $order->items()->create(['sku' => 'WIDGET-01', 'title' => 'Blue Widget', 'qty' => 1, 'price' => 10]);
    $order->refresh();

    expect(evaluator()->evaluate(['all' => [['field' => 'sku', 'value' => 'widget']]], $order))->toBeTrue();
    expect(evaluator()->evaluate(['all' => [['field' => 'product', 'value' => 'Blue']]], $order))->toBeTrue();
    expect(evaluator()->evaluate(['all' => [['field' => 'sku', 'value' => 'nope']]], $order))->toBeFalse();
});

test('customer_country reads from shipping_address', function () {
    $order = Order::factory()->create(['shipping_address' => ['country' => 'AU']]);

    expect(evaluator()->evaluate(['all' => [['field' => 'customer_country', 'operator' => 'eq', 'value' => 'AU']]], $order))->toBeTrue();
    expect(evaluator()->evaluate(['all' => [['field' => 'customer_country', 'operator' => 'eq', 'value' => 'US']]], $order))->toBeFalse();
});

test('repeat_buyer is true only when the same email has another order on the team', function () {
    $team = Team::factory()->create();

    $first = Order::factory()->create(['team_id' => $team->id, 'customer_email' => 'buyer@example.com']);
    expect(evaluator()->evaluate(['all' => [['field' => 'repeat_buyer', 'value' => true]]], $first))->toBeFalse();

    $second = Order::factory()->create(['team_id' => $team->id, 'customer_email' => 'buyer@example.com']);
    expect(evaluator()->evaluate(['all' => [['field' => 'repeat_buyer', 'value' => true]]], $second))->toBeTrue();
});
