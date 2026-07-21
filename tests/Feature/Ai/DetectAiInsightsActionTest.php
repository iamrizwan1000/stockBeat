<?php

use App\Actions\Ai\DetectAiInsightsAction;
use App\Models\AiProviderSetting;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Models\Team;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Contract\Messaging;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);

    // DispatchRuleActionsAction's constructor eagerly resolves the real
    // Firebase Messaging client (same as every other rule-firing action in
    // this codebase, e.g. PushNotificationTest) even though these tests
    // only exercise the 'email' action — bind a mock so container
    // resolution succeeds without a real service-account credential.
    app()->instance(Messaging::class, Mockery::mock(Messaging::class));
});

test('does nothing when the team has no enabled ai_insight rule', function () {
    $team = Team::factory()->create();
    Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 1]);

    Http::fake();

    app(DetectAiInsightsAction::class)->handle($team);

    expect(RuleExecution::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('does nothing when nothing notable is found, even with a rule enabled', function () {
    $team = Team::factory()->create();
    Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_AI_INSIGHT,
        'actions' => [['type' => 'email']],
    ]);

    Http::fake();

    app(DetectAiInsightsAction::class)->handle($team);

    expect(RuleExecution::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a real low-stock signal fires the rule with an AI-narrated body', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_AI_INSIGHT,
        'actions' => [['type' => 'email']],
    ]);
    Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 2]);

    AiProviderSetting::factory()->create(['provider' => AiProviderSetting::PROVIDER_GROQ, 'active' => true]);
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => '1 product has 5 or fewer units left — might be worth restocking.'],
            ]],
        ], 200),
    ]);

    app(DetectAiInsightsAction::class)->handle($team);

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
    $notification = Notification::query()->where('type', Notification::TYPE_RULE_EMAIL)->first();
    expect($notification)->not->toBeNull();
    expect($notification->body)->toBe('1 product has 5 or fewer units left — might be worth restocking.');
});

test('a disconnected store connection is a real signal too', function () {
    $team = Team::factory()->create();
    Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_AI_INSIGHT,
        'actions' => [['type' => 'email']],
    ]);
    StoreConnection::factory()->create(['team_id' => $team->id, 'name' => 'My Shopify Store', 'status' => StoreConnection::STATUS_NEEDS_REAUTH]);

    AiProviderSetting::factory()->create(['provider' => AiProviderSetting::PROVIDER_GROQ, 'active' => true]);
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'My Shopify Store needs reauthorization.'],
            ]],
        ], 200),
    ]);

    app(DetectAiInsightsAction::class)->handle($team);

    expect(RuleExecution::query()->count())->toBe(1);
});

test('never fires when narration fails, even with a real signal present', function () {
    $team = Team::factory()->create();
    Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_AI_INSIGHT,
        'actions' => [['type' => 'email']],
    ]);
    Product::factory()->create(['team_id' => $team->id, 'stock_quantity' => 1]);

    // No active AI provider configured — narration can't happen.
    Http::fake();

    app(DetectAiInsightsAction::class)->handle($team);

    expect(RuleExecution::query()->count())->toBe(0);
});
