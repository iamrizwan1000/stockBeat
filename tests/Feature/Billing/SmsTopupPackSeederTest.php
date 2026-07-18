<?php

use App\Models\SmsTopupPack;
use Database\Seeders\SmsTopupPackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it seeds the sms_100 and sms_500 packs described in Plan §5', function () {
    $this->seed(SmsTopupPackSeeder::class);

    $sms100 = SmsTopupPack::query()->where('key', 'sms_100')->firstOrFail();
    expect($sms100->sms_credits)->toBe(100);
    expect((float) $sms100->price_usd)->toBe(2.99);
    expect($sms100->active)->toBeTrue();

    $sms500 = SmsTopupPack::query()->where('key', 'sms_500')->firstOrFail();
    expect($sms500->sms_credits)->toBe(500);
    expect((float) $sms500->price_usd)->toBe(9.99);
    expect($sms500->active)->toBeTrue();
});

test('running the seeder twice does not create duplicates', function () {
    $this->seed(SmsTopupPackSeeder::class);
    $this->seed(SmsTopupPackSeeder::class);

    expect(SmsTopupPack::query()->count())->toBe(2);
});
