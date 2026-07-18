<?php

use App\Models\ContentBlock;
use Database\Seeders\ContentBlockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it seeds the paywall copy quoted in Plan §5.1', function () {
    $this->seed(ContentBlockSeeder::class);

    expect(ContentBlock::query()->count())->toBeGreaterThan(0);

    $proHeadline = ContentBlock::query()->where('key', 'paywall_pro_headline')->firstOrFail();
    expect($proHeadline->body)->toBe('Pro — $17.99/month');
    expect($proHeadline->locale)->toBe('en');
    expect($proHeadline->active)->toBeTrue();

    $freeBody = ContentBlock::query()->where('key', 'paywall_free_body')->firstOrFail();
    expect($freeBody->body)->toContain('1 connected store (any platform)');
    expect($freeBody->body)->toContain('Last 7 days of orders');

    $premiumBody = ContentBlock::query()->where('key', 'paywall_premium_body')->firstOrFail();
    expect($premiumBody->body)->toContain('order spike & refund spike alerts');

    $starterHeadline = ContentBlock::query()->where('key', 'paywall_starter_headline')->firstOrFail();
    expect($starterHeadline->body)->toBe('Starter — $5.99/month');

    $premiumYearly = ContentBlock::query()->where('key', 'paywall_premium_yearly_headline')->firstOrFail();
    expect($premiumYearly->body)->toBe('Premium Yearly — $429.99/year');
});

test('running the seeder twice does not create duplicates', function () {
    $this->seed(ContentBlockSeeder::class);
    $countAfterFirst = ContentBlock::query()->count();

    $this->seed(ContentBlockSeeder::class);

    expect(ContentBlock::query()->count())->toBe($countAfterFirst);
});
