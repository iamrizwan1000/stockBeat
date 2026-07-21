<?php

use App\Actions\Billing\GetActiveAiTopupPacksAction;
use App\Models\AiTopupPack;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it returns only active packs, sorted by sort_order', function () {
    AiTopupPack::factory()->create(['key' => 'ai_200', 'ai_questions' => 200, 'price_usd' => 14.99, 'active' => true, 'sort_order' => 2]);
    AiTopupPack::factory()->create(['key' => 'ai_50', 'ai_questions' => 50, 'price_usd' => 4.99, 'active' => true, 'sort_order' => 1]);
    AiTopupPack::factory()->create(['key' => 'ai_retired', 'active' => false, 'sort_order' => 0]);

    $result = app(GetActiveAiTopupPacksAction::class)->handle();

    expect($result)->toHaveCount(2);
    expect($result[0]['key'])->toBe('ai_50');
    expect($result[1]['key'])->toBe('ai_200');
    expect(collect($result)->pluck('key'))->not->toContain('ai_retired');
    expect($result[0]['ai_questions'])->toBe(50);
    expect($result[0]['price_usd'])->toBe('4.99');
});

test('it returns an empty array when no packs exist', function () {
    expect(app(GetActiveAiTopupPacksAction::class)->handle())->toBe([]);
});
