<?php

use App\Models\AppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('config is readable without authentication and reflects app_config', function () {
    AppConfig::query()->create(['key' => AppConfig::KEY_MIN_VERSION, 'value' => '2.0.0']);
    AppConfig::query()->create(['key' => AppConfig::KEY_MAINTENANCE_MODE, 'value' => true]);
    AppConfig::query()->create(['key' => AppConfig::KEY_MAINTENANCE_BANNER, 'value' => 'Down for maintenance']);

    test()->getJson('/api/v1/config')
        ->assertOk()
        ->assertJsonPath('data.min_version', '2.0.0')
        ->assertJsonPath('data.maintenance_mode', true)
        ->assertJsonPath('data.maintenance_banner', 'Down for maintenance');
});

test('config defaults to nulls/false when nothing is configured yet', function () {
    test()->getJson('/api/v1/config')
        ->assertOk()
        ->assertJsonPath('data.min_version', null)
        ->assertJsonPath('data.maintenance_mode', false)
        ->assertJsonPath('data.maintenance_banner', null);
});
