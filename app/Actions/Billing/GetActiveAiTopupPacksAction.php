<?php

namespace App\Actions\Billing;

use App\Models\AiTopupPack;

/**
 * Serves the admin-editable AI question top-up pack catalog to the mobile
 * app — composed into `/me` alongside `sms_topup_packs`
 * ({@see GetActiveSmsTopupPacksAction}, same pattern). Only `active` packs
 * are exposed, ordered the way they should be presented in the purchase
 * sheet.
 */
class GetActiveAiTopupPacksAction
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(): array
    {
        return AiTopupPack::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get()
            ->map(fn (AiTopupPack $pack) => [
                'key' => $pack->key,
                'name' => $pack->name,
                'ai_questions' => $pack->ai_questions,
                'price_usd' => (string) $pack->price_usd,
            ])
            ->all();
    }
}
