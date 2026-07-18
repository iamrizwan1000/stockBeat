<?php

namespace App\Actions\Content;

use App\Actions\FeatureFlags\GetFeatureFlagsForTeamAction;
use App\Models\ContentBlock;

/**
 * Plan §5.1/§8.7.3: serves admin-editable paywall & store-listing copy to
 * the mobile app as a `key => body` map — composed into `/me` alongside
 * `feature_flags` (same pattern as
 * {@see GetFeatureFlagsForTeamAction}) so an
 * admin can tweak paywall copy with zero app release. Only `active` blocks
 * are exposed. There's no locale-detection mechanism anywhere else in the
 * app yet (Plan §4.10 i18n is aspirational), so this deliberately always
 * serves `en` — the `locale` param exists for when that lands.
 */
class GetActiveContentBlocksAction
{
    /**
     * @return array<string, string>
     */
    public function handle(string $locale = 'en'): array
    {
        return ContentBlock::query()
            ->where('active', true)
            ->where('locale', $locale)
            ->get()
            ->mapWithKeys(fn (ContentBlock $block) => [$block->key => $block->body])
            ->all();
    }
}
