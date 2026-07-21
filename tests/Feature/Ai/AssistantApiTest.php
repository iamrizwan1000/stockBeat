<?php

use App\Models\AiProviderSetting;
use App\Models\AiUsageLedger;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedAssistantUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

function activateGroqProvider(): void
{
    AiProviderSetting::factory()->create([
        'provider' => AiProviderSetting::PROVIDER_GROQ,
        'model' => 'llama-3.3-70b-versatile',
        'active' => true,
    ]);
}

function fakeGroqToolCallThenAnswer(string $finalAnswer = 'You made $120.00 today across 3 orders.'): void
{
    Http::fake([
        'api.groq.com/*' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'get_sales_summary',
                                'arguments' => json_encode(['range' => 'today']),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => $finalAnswer],
                ]],
            ], 200),
    ]);
}

test('assistant endpoints require authentication', function () {
    test()->postJson('/api/v1/assistant/ask', ['question' => 'How am I doing today?'])->assertUnauthorized();
});

test('a fresh trial team can ask a question and gets a real, grounded answer', function () {
    // Fresh signups are on a Premium trial (Plan §6.3), so AI is enabled by default.
    onboardedAssistantUser();
    activateGroqProvider();
    fakeGroqToolCallThenAnswer();

    $response = test()->postJson('/api/v1/assistant/ask', ['question' => 'How much did I make today?']);

    $response->assertOk();
    // user question, assistant tool-call request, tool result, assistant final answer.
    expect($response->json('data.conversation.messages'))->toHaveCount(4);
    $finalMessage = collect($response->json('data.conversation.messages'))->last();
    expect($finalMessage['content'])->toBe('You made $120.00 today across 3 orders.');

    Http::assertSentCount(2); // one round asking for the tool, one round with the final answer
});

test('asking a question debits the team\'s monthly AI question quota', function () {
    $user = onboardedAssistantUser();
    activateGroqProvider();
    fakeGroqToolCallThenAnswer();

    test()->postJson('/api/v1/assistant/ask', ['question' => 'How much did I make today?'])->assertOk();

    $team = $user->fresh()->currentTeam();
    expect(AiUsageLedger::questionsUsedThisMonth($team->id))->toBe(1);
});

test('a follow-up question in the same conversation reuses it rather than creating a new one', function () {
    onboardedAssistantUser();
    activateGroqProvider();

    // Both questions' tool-call + answer rounds must be registered in one
    // Http::fake() call — a second, separate Http::fake() call doesn't
    // override the first's still-registered (now-exhausted) sequence for
    // the same URL pattern (first-match-wins, not last-match-wins).
    Http::fake([
        'api.groq.com/*' => Http::sequence()
            ->push(['choices' => [['message' => [
                'role' => 'assistant', 'content' => null,
                'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_sales_summary', 'arguments' => json_encode(['range' => 'today'])]]],
            ]]]], 200)
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'You made $120.00 today across 3 orders.']]]], 200)
            ->push(['choices' => [['message' => [
                'role' => 'assistant', 'content' => null,
                'tool_calls' => [['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'get_sales_summary', 'arguments' => json_encode(['range' => '7d'])]]],
            ]]]], 200)
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Yesterday you made $80.00 across 2 orders.']]]], 200),
    ]);

    $first = test()->postJson('/api/v1/assistant/ask', ['question' => 'How much did I make today?'])->assertOk();
    $conversationId = $first->json('data.conversation.id');

    $second = test()->postJson('/api/v1/assistant/ask', [
        'question' => 'What about yesterday?',
        'conversation_id' => $conversationId,
    ])->assertOk();

    expect($second->json('data.conversation.id'))->toBe($conversationId);
    expect($second->json('data.conversation.messages'))->toHaveCount(8);
});

test('a team on a plan without AI enabled is blocked with a clear message, not a provider call', function () {
    $user = onboardedAssistantUser();
    activateGroqProvider();

    $team = $user->fresh()->currentTeam();
    $team->subscription()->update(['status' => Subscription::STATUS_EXPIRED]);

    Http::fake(); // no request should ever be made

    $response = test()->postJson('/api/v1/assistant/ask', ['question' => 'How much did I make today?']);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('Upgrade');
    Http::assertNothingSent();
});

test('quota exhaustion blocks further questions without calling the provider', function () {
    $user = onboardedAssistantUser();
    activateGroqProvider();

    $team = $user->fresh()->currentTeam();
    // Premium's seeded monthly limit is 500 — burn through it without real HTTP calls.
    AiUsageLedger::factory()->count(500)->create(['team_id' => $team->id]);

    Http::fake();

    $response = test()->postJson('/api/v1/assistant/ask', ['question' => 'How much did I make today?']);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain("You've used all 500");
    Http::assertNothingSent();
});

test('admin-granted bonus AI credits raise the effective cap for the current month', function () {
    $user = onboardedAssistantUser();
    activateGroqProvider();

    $team = $user->fresh()->currentTeam();
    AiUsageLedger::factory()->count(500)->create(['team_id' => $team->id]);
    // Without a bonus grant this would 422 (see the test above) — a real admin comp lifts it.
    AiUsageLedger::query()->create([
        'team_id' => $team->id,
        'delta' => 10,
        'reason' => AiUsageLedger::REASON_TOPUP_IAP,
        'balance_after' => 10,
    ]);

    fakeGroqToolCallThenAnswer();

    test()->postJson('/api/v1/assistant/ask', ['question' => 'How much did I make today?'])->assertOk();
});

test('no active provider configured returns a clear 502, not a 500', function () {
    onboardedAssistantUser();
    // No AiProviderSetting created at all.

    $response = test()->postJson('/api/v1/assistant/ask', ['question' => 'How much did I make today?']);

    $response->assertStatus(502);
    expect($response->json('message'))->toContain('No active AI provider');
});

test('rule builder is blocked on a plan without it enabled', function () {
    $user = onboardedAssistantUser();
    activateGroqProvider();

    $team = $user->fresh()->currentTeam();
    $team->subscription()->update(['plan_key' => Plan::STARTER]);

    Http::fake();

    $response = test()->postJson('/api/v1/assistant/rule-draft', ['prompt' => 'notify me by text when an order is over $200']);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('Pro plan');
    Http::assertNothingSent();
});

test('rule builder turns a prompt into a valid rule draft', function () {
    $user = onboardedAssistantUser();
    activateGroqProvider();

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'name' => 'High-value eBay orders',
                        'trigger' => 'high_value_order',
                        'conditions' => ['all' => [['field' => 'total', 'operator' => 'gte', 'value' => 200], ['field' => 'channel', 'operator' => 'eq', 'value' => 'ebay']]],
                        'actions' => [['type' => 'sms']],
                        'controls' => [],
                    ]),
                ],
            ]],
        ], 200),
    ]);

    $response = test()->postJson('/api/v1/assistant/rule-draft', ['prompt' => 'notify me by text when an ebay order is over $200']);

    $response->assertOk();
    expect($response->json('data.valid'))->toBeTrue();
    expect($response->json('data.draft.trigger'))->toBe('high_value_order');
});

test('a rule draft using symbol operators instead of the real word-based vocabulary is correctly flagged invalid', function () {
    // Regression test: an earlier version of the system prompt told the model
    // to use symbols (">=", "=") which ConditionEvaluator doesn't recognize —
    // such a rule would validate, save, and then silently never fire. The
    // validator must catch this at draft time, not let it through as "valid".
    $user = onboardedAssistantUser();
    activateGroqProvider();

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'name' => 'High-value eBay orders',
                        'trigger' => 'high_value_order',
                        'conditions' => ['all' => [['field' => 'total', 'operator' => '>=', 'value' => 200]]],
                        'actions' => [['type' => 'sms']],
                        'controls' => [],
                    ]),
                ],
            ]],
        ], 200),
    ]);

    $response = test()->postJson('/api/v1/assistant/rule-draft', ['prompt' => 'notify me by text when an ebay order is over $200']);

    $response->assertOk();
    expect($response->json('data.valid'))->toBeFalse();
    expect($response->json('data.errors'))->toHaveKey('conditions.all.0.operator');
});

test('App Help works on a Free-tier team with no AI question quota, and never debits it', function () {
    $user = onboardedAssistantUser();
    activateGroqProvider();

    $team = $user->fresh()->currentTeam();
    $team->subscription()->update(['status' => Subscription::STATUS_EXPIRED]); // → Free

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Go to Connections and tap "Add store" to connect Shopify.'],
            ]],
        ], 200),
    ]);

    $response = test()->postJson('/api/v1/assistant/ask', [
        'question' => 'How do I connect my Shopify store?',
        'mode' => 'help',
    ]);

    $response->assertOk();
    expect(AiUsageLedger::questionsUsedThisMonth($team->id))->toBe(0);
});

test('App Help mode never offers data tools, even on a plan that has the Data Copilot', function () {
    onboardedAssistantUser();
    activateGroqProvider();

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'That needs the Data Copilot — try asking from the main Ask AI screen.'],
            ]],
        ], 200),
    ]);

    test()->postJson('/api/v1/assistant/ask', [
        'question' => 'How much did I make today?',
        'mode' => 'help',
    ])->assertOk();

    $sentPayload = null;
    Http::assertSent(function ($request) use (&$sentPayload) {
        $sentPayload = $request->data();

        return true;
    });

    $toolNames = collect($sentPayload['tools'] ?? [])->pluck('function.name')->all();
    expect($toolNames)->not->toContain('get_sales_summary');
    expect($toolNames)->toContain('get_connection_health');
});
