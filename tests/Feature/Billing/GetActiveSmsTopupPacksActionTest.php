<?php

use App\Actions\Billing\GetActiveSmsTopupPacksAction;
use App\Models\SmsTopupPack;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it returns only active packs, sorted by sort_order', function () {
    SmsTopupPack::factory()->create(['key' => 'sms_500', 'sms_credits' => 500, 'price_usd' => 9.99, 'active' => true, 'sort_order' => 2]);
    SmsTopupPack::factory()->create(['key' => 'sms_100', 'sms_credits' => 100, 'price_usd' => 2.99, 'active' => true, 'sort_order' => 1]);
    SmsTopupPack::factory()->create(['key' => 'sms_retired', 'active' => false, 'sort_order' => 0]);

    $result = app(GetActiveSmsTopupPacksAction::class)->handle();

    expect($result)->toHaveCount(2);
    expect($result[0]['key'])->toBe('sms_100');
    expect($result[1]['key'])->toBe('sms_500');
    expect(collect($result)->pluck('key'))->not->toContain('sms_retired');
    expect($result[0]['sms_credits'])->toBe(100);
    expect($result[0]['price_usd'])->toBe('2.99');
});

test('it returns an empty array when no packs exist', function () {
    expect(app(GetActiveSmsTopupPacksAction::class)->handle())->toBe([]);
});
