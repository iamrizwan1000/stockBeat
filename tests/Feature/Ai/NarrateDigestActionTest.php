<?php

use App\Actions\Ai\NarrateDigestAction;
use App\Models\AiProviderSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('returns a real AI-narrated sentence when a provider is active and answers', function () {
    AiProviderSetting::factory()->create(['provider' => AiProviderSetting::PROVIDER_GROQ, 'active' => true]);

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Solid day — 5 orders and $200 in the bank, led by Widget Pro.'],
            ]],
        ], 200),
    ]);

    $result = app(NarrateDigestAction::class)->handle(5, 200.0, 'Widget Pro');

    expect($result)->toBe('Solid day — 5 orders and $200 in the bank, led by Widget Pro.');
});

test('returns null (never throws) when no provider is configured, so the caller can fall back to the template', function () {
    $result = app(NarrateDigestAction::class)->handle(5, 200.0, 'Widget Pro');

    expect($result)->toBeNull();
});

test('returns null (never throws) when the active provider errors', function () {
    AiProviderSetting::factory()->create(['provider' => AiProviderSetting::PROVIDER_GROQ, 'active' => true]);

    Http::fake(['api.groq.com/*' => Http::response([], 500)]);

    $result = app(NarrateDigestAction::class)->handle(5, 200.0, 'Widget Pro');

    expect($result)->toBeNull();
});
