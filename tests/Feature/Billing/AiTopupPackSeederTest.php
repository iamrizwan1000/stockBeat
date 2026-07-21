<?php

use App\Models\AiTopupPack;
use Database\Seeders\AiTopupPackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it seeds the ai_50 and ai_200 packs', function () {
    $this->seed(AiTopupPackSeeder::class);

    $ai50 = AiTopupPack::query()->where('key', 'ai_50')->firstOrFail();
    expect($ai50->ai_questions)->toBe(50);
    expect((float) $ai50->price_usd)->toBe(4.99);
    expect($ai50->active)->toBeTrue();

    $ai200 = AiTopupPack::query()->where('key', 'ai_200')->firstOrFail();
    expect($ai200->ai_questions)->toBe(200);
    expect((float) $ai200->price_usd)->toBe(14.99);
    expect($ai200->active)->toBeTrue();
});

test('running the seeder twice does not create duplicates', function () {
    $this->seed(AiTopupPackSeeder::class);
    $this->seed(AiTopupPackSeeder::class);

    expect(AiTopupPack::query()->count())->toBe(2);
});
