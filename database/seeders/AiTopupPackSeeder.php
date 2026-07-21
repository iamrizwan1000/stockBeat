<?php

namespace Database\Seeders;

use App\Actions\Billing\ProcessRevenueCatEventAction;
use App\Models\AiTopupPack;
use Illuminate\Database\Seeder;

/**
 * Seeds the AI question top-up packs — the AI-question counterpart to
 * `SmsTopupPackSeeder`. `key` matches the RevenueCat product id
 * ({@see ProcessRevenueCatEventAction}) so a fresh
 * install can immediately credit real purchases without an admin having to
 * populate the catalog by hand first.
 */
class AiTopupPackSeeder extends Seeder
{
    public function run(): void
    {
        AiTopupPack::query()->updateOrCreate(
            ['key' => 'ai_50'],
            [
                'name' => '50 AI questions',
                'ai_questions' => 50,
                'price_usd' => 4.99,
                'active' => true,
                'sort_order' => 1,
            ],
        );

        AiTopupPack::query()->updateOrCreate(
            ['key' => 'ai_200'],
            [
                'name' => '200 AI questions',
                'ai_questions' => 200,
                'price_usd' => 14.99,
                'active' => true,
                'sort_order' => 2,
            ],
        );
    }
}
