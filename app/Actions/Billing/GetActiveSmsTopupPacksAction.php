<?php

namespace App\Actions\Billing;

use App\Actions\FeatureFlags\GetFeatureFlagsForTeamAction;
use App\Models\SmsTopupPack;

/**
 * Plan §5/§8.7.3: serves the admin-editable SMS top-up pack catalog to the
 * mobile app — composed into `/me` alongside `feature_flags` (same pattern
 * as {@see GetFeatureFlagsForTeamAction}) so an
 * admin can add/retire a pack or correct its display price with zero app
 * changes. Only `active` packs are exposed, ordered the way they should be
 * presented in the purchase sheet.
 */
class GetActiveSmsTopupPacksAction
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(): array
    {
        return SmsTopupPack::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get()
            ->map(fn (SmsTopupPack $pack) => [
                'key' => $pack->key,
                'name' => $pack->name,
                'sms_credits' => $pack->sms_credits,
                'price_usd' => (string) $pack->price_usd,
            ])
            ->all();
    }
}
