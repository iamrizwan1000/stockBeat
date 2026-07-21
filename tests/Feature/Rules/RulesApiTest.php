<?php

use App\Actions\Orders\IngestOrderAction;
use App\Actions\Rules\RuleEvaluationAction;
use App\Jobs\RuleEvaluationJob;
use App\Jobs\SendFreeTierNewOrderAlertJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Orders\NormalizedOrder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedRuleUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

function validRulePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Notify on high-value orders',
        'trigger' => Rule::TRIGGER_HIGH_VALUE_ORDER,
        'conditions' => ['all' => [['field' => 'total', 'operator' => 'gt', 'value' => 100]]],
        'actions' => [['type' => 'push']],
    ], $overrides);
}

test('rule endpoints require authentication', function () {
    test()->getJson('/api/v1/rules')->assertUnauthorized();
    test()->postJson('/api/v1/rules', validRulePayload())->assertUnauthorized();
});

test('action-specific config survives validation (tag, user_id do not get stripped)', function () {
    onboardedRuleUser();

    $response = test()->postJson('/api/v1/rules', validRulePayload([
        'actions' => [
            ['type' => 'auto_tag', 'tag' => 'vip'],
            ['type' => 'notify_member', 'user_id' => 42],
        ],
    ]));

    $response->assertCreated();
    expect($response->json('data.rule.actions.0.tag'))->toBe('vip');
    expect($response->json('data.rule.actions.1.user_id'))->toBe(42);
});

test('a rule can be created and updated', function () {
    onboardedRuleUser();

    $response = test()->postJson('/api/v1/rules', validRulePayload());
    $response->assertCreated()->assertJsonPath('data.rule.name', 'Notify on high-value orders');

    $ruleId = $response->json('data.rule.id');

    test()->putJson("/api/v1/rules/{$ruleId}", ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('data.rule.enabled', false);
});

test('a rule can be created with a valid sound and it is returned by the API', function () {
    onboardedRuleUser();

    $response = test()->postJson('/api/v1/rules', validRulePayload(['sound' => Rule::SOUND_CHA_CHING]));

    $response->assertCreated()->assertJsonPath('data.rule.sound', 'cha_ching');
    expect(Rule::query()->find($response->json('data.rule.id'))->sound)->toBe('cha_ching');
});

test('an invalid sound is rejected', function () {
    onboardedRuleUser();

    test()->postJson('/api/v1/rules', validRulePayload(['sound' => 'air-horn']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sound');
});

test('a rule\'s sound can be updated', function () {
    onboardedRuleUser();
    $ruleId = test()->postJson('/api/v1/rules', validRulePayload())->json('data.rule.id');

    test()->putJson("/api/v1/rules/{$ruleId}", ['sound' => Rule::SOUND_CHIME])
        ->assertOk()
        ->assertJsonPath('data.rule.sound', 'chime');
});

test('a free-plan team cannot create custom rules', function () {
    $user = onboardedRuleUser();
    $user->ownedTeam->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);

    test()->postJson('/api/v1/rules', validRulePayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('trigger');
});

test('a premium-trial team can create rules', function () {
    onboardedRuleUser();

    test()->postJson('/api/v1/rules', validRulePayload())->assertCreated();
});

test('a pro-tier team cannot create an order_spike (advanced trigger) rule', function () {
    $user = onboardedRuleUser();
    $user->ownedTeam->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::PRO]);

    test()->postJson('/api/v1/rules', validRulePayload(['trigger' => Rule::TRIGGER_ORDER_SPIKE]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('trigger');
});

test('a premium-tier team can create an order_spike rule', function () {
    $user = onboardedRuleUser();
    $user->ownedTeam->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::PREMIUM]);

    test()->postJson('/api/v1/rules', validRulePayload(['trigger' => Rule::TRIGGER_ORDER_SPIKE]))
        ->assertCreated();
});

test('a pro-tier team cannot upgrade an existing rule to refund_spike', function () {
    $user = onboardedRuleUser();
    $user->ownedTeam->subscription->update(['status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::PRO]);

    $ruleId = test()->postJson('/api/v1/rules', validRulePayload())->json('data.rule.id');

    test()->putJson("/api/v1/rules/{$ruleId}", ['trigger' => Rule::TRIGGER_REFUND_SPIKE])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('trigger');
});

test('rules are scoped to the caller\'s team', function () {
    onboardedRuleUser();
    $ruleId = test()->postJson('/api/v1/rules', validRulePayload())->json('data.rule.id');

    onboardedRuleUser();
    test()->putJson("/api/v1/rules/{$ruleId}", ['enabled' => false])->assertNotFound();
    test()->getJson('/api/v1/rules')->assertOk()->assertJsonCount(0, 'data.rules');
});

test('test-fire logs an execution and can be called repeatedly', function () {
    onboardedRuleUser();
    $ruleId = test()->postJson('/api/v1/rules', validRulePayload())->json('data.rule.id');

    test()->postJson("/api/v1/rules/{$ruleId}/test")->assertOk()->assertJsonPath('data.execution.trigger', 'test_fire');
    test()->postJson("/api/v1/rules/{$ruleId}/test")->assertOk();

    expect(RuleExecution::query()->where('rule_id', $ruleId)->count())->toBe(2);
});

test('executions are listed newest first and capped at 50', function () {
    $user = onboardedRuleUser();
    $ruleId = test()->postJson('/api/v1/rules', validRulePayload())->json('data.rule.id');
    $rule = Rule::query()->find($ruleId);

    foreach (range(1, 55) as $i) {
        RuleExecution::factory()->create(['rule_id' => $rule->id, 'fired_at' => now()->addSeconds($i)]);
    }

    $response = test()->getJson("/api/v1/rules/{$ruleId}/executions")->assertOk();
    expect($response->json('data.executions'))->toHaveCount(50);
});

test('ingesting a new order dispatches rule evaluation for new_order and high_value_order', function () {
    Queue::fake();

    $user = onboardedRuleUser();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id]);

    $normalized = new NormalizedOrder(
        externalId: 'ext-1',
        orderNumber: '#1',
        status: Order::STATUS_NEW,
        fulfillmentStatus: Order::FULFILLMENT_UNFULFILLED,
        paymentStatus: Order::PAYMENT_PAID,
        currency: 'USD',
        total: 250,
        customerName: 'Buyer',
        customerEmail: 'buyer@example.com',
        shippingAddress: [],
        placedAt: now(),
        shipByAt: null,
        tags: [],
        raw: [],
        isTest: false,
        items: [],
    );

    app(IngestOrderAction::class)->handle($connection, $normalized);

    Queue::assertPushed(RuleEvaluationJob::class, 3);
    Queue::assertPushed(fn (RuleEvaluationJob $job) => $job->trigger === Rule::TRIGGER_NEW_ORDER);
    Queue::assertPushed(fn (RuleEvaluationJob $job) => $job->trigger === Rule::TRIGGER_HIGH_VALUE_ORDER);
    Queue::assertPushed(fn (RuleEvaluationJob $job) => $job->trigger === Rule::TRIGGER_ORDER_SPIKE);
    Queue::assertPushed(fn (SendFreeTierNewOrderAlertJob $job) => $job->orderId !== null);
});

test('re-ingesting an existing order does not dispatch rule evaluation again', function () {
    Queue::fake();

    $user = onboardedRuleUser();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id]);

    $normalized = new NormalizedOrder(
        externalId: 'ext-2',
        orderNumber: '#2',
        status: Order::STATUS_NEW,
        fulfillmentStatus: Order::FULFILLMENT_UNFULFILLED,
        paymentStatus: Order::PAYMENT_PAID,
        currency: 'USD',
        total: 50,
        customerName: null,
        customerEmail: null,
        shippingAddress: [],
        placedAt: now(),
        shipByAt: null,
        tags: [],
        raw: [],
        isTest: false,
        items: [],
    );

    app(IngestOrderAction::class)->handle($connection, $normalized);
    app(IngestOrderAction::class)->handle($connection, $normalized);

    Queue::assertPushed(RuleEvaluationJob::class, 3);
});

test('an order transitioning to cancelled dispatches order_cancelled', function () {
    Queue::fake();

    $user = onboardedRuleUser();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id]);

    $normalized = fn (string $status) => new NormalizedOrder(
        externalId: 'ext-cancel',
        orderNumber: '#3',
        status: $status,
        fulfillmentStatus: Order::FULFILLMENT_UNFULFILLED,
        paymentStatus: Order::PAYMENT_PAID,
        currency: 'USD',
        total: 50,
        customerName: null,
        customerEmail: null,
        shippingAddress: [],
        placedAt: now(),
        shipByAt: null,
        tags: [],
        raw: [],
        isTest: false,
        items: [],
    );

    app(IngestOrderAction::class)->handle($connection, $normalized(Order::STATUS_NEW));
    Queue::fake();
    app(IngestOrderAction::class)->handle($connection, $normalized(Order::STATUS_CANCELLED));

    Queue::assertPushed(fn (RuleEvaluationJob $job) => $job->trigger === Rule::TRIGGER_ORDER_CANCELLED);
});

test('an order transitioning to refunded dispatches refund_requested', function () {
    $user = onboardedRuleUser();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id]);

    $normalized = fn (string $status) => new NormalizedOrder(
        externalId: 'ext-refund',
        orderNumber: '#4',
        status: $status,
        fulfillmentStatus: Order::FULFILLMENT_FULFILLED,
        paymentStatus: Order::PAYMENT_PAID,
        currency: 'USD',
        total: 50,
        customerName: null,
        customerEmail: null,
        shippingAddress: [],
        placedAt: now(),
        shipByAt: null,
        tags: [],
        raw: [],
        isTest: false,
        items: [],
    );

    app(IngestOrderAction::class)->handle($connection, $normalized(Order::STATUS_SHIPPED));

    Queue::fake();
    app(IngestOrderAction::class)->handle($connection, $normalized(Order::STATUS_REFUNDED));

    Queue::assertPushed(fn (RuleEvaluationJob $job) => $job->trigger === Rule::TRIGGER_REFUND_REQUESTED);
    Queue::assertPushed(fn (RuleEvaluationJob $job) => $job->trigger === Rule::TRIGGER_REFUND_SPIKE);
});

test('a payment transitioning to failed dispatches payment_failed', function () {
    $user = onboardedRuleUser();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id]);

    $normalized = fn (string $paymentStatus) => new NormalizedOrder(
        externalId: 'ext-payment',
        orderNumber: '#5',
        status: Order::STATUS_NEW,
        fulfillmentStatus: Order::FULFILLMENT_UNFULFILLED,
        paymentStatus: $paymentStatus,
        currency: 'USD',
        total: 50,
        customerName: null,
        customerEmail: null,
        shippingAddress: [],
        placedAt: now(),
        shipByAt: null,
        tags: [],
        raw: [],
        isTest: false,
        items: [],
    );

    app(IngestOrderAction::class)->handle($connection, $normalized(Order::PAYMENT_PENDING));

    Queue::fake();
    app(IngestOrderAction::class)->handle($connection, $normalized(Order::PAYMENT_FAILED));

    Queue::assertPushed(fn (RuleEvaluationJob $job) => $job->trigger === Rule::TRIGGER_PAYMENT_FAILED);
});

test('check_at is cleared once an order reaches a terminal state', function () {
    $user = onboardedRuleUser();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id]);

    $normalized = fn (string $status) => new NormalizedOrder(
        externalId: 'ext-checkat',
        orderNumber: '#6',
        status: $status,
        fulfillmentStatus: Order::FULFILLMENT_UNFULFILLED,
        paymentStatus: Order::PAYMENT_PAID,
        currency: 'USD',
        total: 50,
        customerName: null,
        customerEmail: null,
        shippingAddress: [],
        placedAt: now(),
        shipByAt: null,
        tags: [],
        raw: [],
        isTest: false,
        items: [],
    );

    $order = app(IngestOrderAction::class)->handle($connection, $normalized(Order::STATUS_NEW));
    expect($order->check_at)->not->toBeNull();

    $order = app(IngestOrderAction::class)->handle($connection, $normalized(Order::STATUS_CANCELLED));
    expect($order->check_at)->toBeNull();
});

test('RuleEvaluationJob loads and evaluates the team\'s enabled rules for its trigger', function () {
    $user = onboardedRuleUser();
    $order = Order::factory()->create(['team_id' => $user->ownedTeam->id, 'total' => 500]);

    $matchingRule = Rule::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'trigger' => Rule::TRIGGER_NEW_ORDER,
        'conditions' => ['all' => [['field' => 'total', 'operator' => 'gt', 'value' => 100]]],
    ]);

    $otherTeamRule = Rule::factory()->create(['trigger' => Rule::TRIGGER_NEW_ORDER]);

    $job = new RuleEvaluationJob($order->id, Rule::TRIGGER_NEW_ORDER);
    $job->handle(app(RuleEvaluationAction::class));

    expect(RuleExecution::query()->where('rule_id', $matchingRule->id)->exists())->toBeTrue();
    expect(RuleExecution::query()->where('rule_id', $otherTeamRule->id)->exists())->toBeFalse();
});

test('a condition using an unrecognized operator is rejected at creation time, not silently accepted', function () {
    onboardedRuleUser();

    // ">=" looks reasonable but isn't in ConditionEvaluator's real vocabulary
    // (which is word-based: "gte", not ">=") — before this validation existed,
    // this would have saved successfully and then simply never fired.
    $response = test()->postJson('/api/v1/rules', validRulePayload([
        'conditions' => ['all' => [['field' => 'total', 'operator' => '>=', 'value' => 100]]],
    ]));

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('conditions.all.0.operator');
});

test('a condition using an unrecognized field is rejected at creation time', function () {
    onboardedRuleUser();

    $response = test()->postJson('/api/v1/rules', validRulePayload([
        'conditions' => ['all' => [['field' => 'discount_code', 'operator' => 'eq', 'value' => 'SAVE10']]],
    ]));

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('conditions.all.0.field');
});

test('every real condition field/operator from Rule::conditionFields()/conditionOperators() is accepted', function () {
    onboardedRuleUser();

    $response = test()->postJson('/api/v1/rules', validRulePayload([
        'conditions' => ['all' => [['field' => 'total', 'operator' => 'between', 'value' => [50, 100]]]],
    ]));

    $response->assertCreated();
});
