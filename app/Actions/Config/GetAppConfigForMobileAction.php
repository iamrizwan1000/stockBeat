<?php

namespace App\Actions\Config;

use App\Models\AppConfig;

/**
 * Serves the admin-editable `app_config` values (Plan §8.7.7/§9) to the
 * mobile app without a release — `min_version` (force-update screen, Plan
 * §17.7 "App version below minimum → force-update screen (from `/config`)")
 * and `maintenance_mode`/`maintenance_banner` (Plan §17.7 "maintenance
 * banner from `/config`"). Deliberately served from an unauthenticated
 * route: a killed app version or a maintenance banner must be checkable
 * before the user has signed in. Announcements already have their own
 * audience-matched, authenticated endpoint (`GET /announcements`) and are
 * intentionally not duplicated here.
 */
class GetAppConfigForMobileAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $config = AppConfig::query()->get()->mapWithKeys(fn (AppConfig $row) => [$row->key => $row->value]);

        return [
            'min_version' => $config[AppConfig::KEY_MIN_VERSION] ?? null,
            'maintenance_mode' => (bool) ($config[AppConfig::KEY_MAINTENANCE_MODE] ?? false),
            'maintenance_banner' => $config[AppConfig::KEY_MAINTENANCE_BANNER] ?? null,
        ];
    }
}
