<?php

namespace Database\Seeders;

use App\Actions\Billing\ProcessRevenueCatEventAction;
use App\Models\SmsTopupPack;
use Illuminate\Database\Seeder;

/**
 * Seeds the SMS credit top-up packs described in Plan §5/§6.1: "SMS top-up
 * packs (consumable IAP): 100 credits / $2.99 · 500 credits / $9.99." The
 * `key` matches the RevenueCat product id
 * ({@see ProcessRevenueCatEventAction}) so a fresh
 * install can immediately credit real purchases without an admin having to
 * populate the catalog by hand first.
 */
class SmsTopupPackSeeder extends Seeder
{
    public function run(): void
    {
        SmsTopupPack::query()->updateOrCreate(
            ['key' => 'sms_100'],
            [
                'name' => '100 SMS',
                'sms_credits' => 100,
                'price_usd' => 2.99,
                'active' => true,
                'sort_order' => 1,
            ],
        );

        SmsTopupPack::query()->updateOrCreate(
            ['key' => 'sms_500'],
            [
                'name' => '500 SMS',
                'sms_credits' => 500,
                'price_usd' => 9.99,
                'active' => true,
                'sort_order' => 2,
            ],
        );
    }
}
