<?php

use App\Actions\Content\GetActiveContentBlocksAction;
use App\Models\ContentBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it returns a key => body map of active en blocks only', function () {
    ContentBlock::factory()->create(['key' => 'paywall_pro_headline', 'body' => 'Pro — $17.99/month', 'locale' => 'en', 'active' => true]);
    ContentBlock::factory()->create(['key' => 'paywall_free_headline', 'body' => 'Free', 'locale' => 'en', 'active' => true]);
    ContentBlock::factory()->create(['key' => 'paywall_retired', 'body' => 'Should not appear', 'locale' => 'en', 'active' => false]);
    ContentBlock::factory()->create(['key' => 'paywall_pro_headline_fr', 'body' => 'Pro', 'locale' => 'fr', 'active' => true]);

    $result = app(GetActiveContentBlocksAction::class)->handle();

    expect($result)->toBe([
        'paywall_pro_headline' => 'Pro — $17.99/month',
        'paywall_free_headline' => 'Free',
    ]);
});

test('it defaults to the en locale', function () {
    ContentBlock::factory()->create(['key' => 'en_block', 'body' => 'English', 'locale' => 'en', 'active' => true]);
    ContentBlock::factory()->create(['key' => 'fr_block', 'body' => 'French', 'locale' => 'fr', 'active' => true]);

    $result = app(GetActiveContentBlocksAction::class)->handle();

    expect($result)->toBe(['en_block' => 'English']);
});

test('it returns an empty array when no blocks exist', function () {
    expect(app(GetActiveContentBlocksAction::class)->handle())->toBe([]);
});
