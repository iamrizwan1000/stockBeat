<?php

use App\Models\AiProviderSetting;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\Subscription;
use App\Models\Team;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Contract\Messaging;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    app()->instance(Messaging::class, Mockery::mock(Messaging::class));
});

test('only scans teams whose plan has ai_proactive_insights_enabled', function () {
    $premiumTeam = Team::factory()->create();
    Subscription::factory()->create(['team_id' => $premiumTeam->id, 'status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::PREMIUM]);
    Rule::factory()->create(['team_id' => $premiumTeam->id, 'trigger' => Rule::TRIGGER_AI_INSIGHT, 'actions' => [['type' => 'email']]]);
    Product::factory()->create(['team_id' => $premiumTeam->id, 'stock_quantity' => 1]);

    $proTeam = Team::factory()->create();
    Subscription::factory()->create(['team_id' => $proTeam->id, 'status' => Subscription::STATUS_ACTIVE, 'plan_key' => Plan::PRO]);
    Rule::factory()->create(['team_id' => $proTeam->id, 'trigger' => Rule::TRIGGER_AI_INSIGHT, 'actions' => [['type' => 'email']]]);
    Product::factory()->create(['team_id' => $proTeam->id, 'stock_quantity' => 1]);

    AiProviderSetting::factory()->create(['provider' => AiProviderSetting::PROVIDER_GROQ, 'active' => true]);
    Http::fake([
        'api.groq.com/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Low stock detected.']]]], 200),
    ]);

    test()->artisan('ai:detect-insights')->assertExitCode(0);

    expect(RuleExecution::query()->where('rule_id', Rule::query()->where('team_id', $premiumTeam->id)->value('id'))->count())->toBe(1);
    expect(RuleExecution::query()->where('rule_id', Rule::query()->where('team_id', $proTeam->id)->value('id'))->count())->toBe(0);
});
