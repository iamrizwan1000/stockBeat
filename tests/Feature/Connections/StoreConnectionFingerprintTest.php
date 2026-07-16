<?php

use App\Models\StoreConnection;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    Http::fake([
        '*/wp-json/wc/v3/orders*' => Http::response([], 200),
        '*/wp-json/wc/v3/webhooks*' => Http::response(['id' => 123], 200),
    ]);
});

function fingerprintTestSeller(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

test('connecting a woo store persists a fingerprint derived from its store url', function () {
    fingerprintTestSeller();

    test()->postJson('/api/v1/connections/woo/start', [
        'name' => 'My Woo Store',
        'credentials' => [
            'store_url' => 'https://example-shop.test',
            'consumer_key' => 'ck_key',
            'consumer_secret' => 'cs_secret',
        ],
    ])->assertCreated();

    $connection = StoreConnection::query()->firstOrFail();
    expect($connection->fingerprint)->not->toBeNull();
});

test('the same store connected under two different teams shares the same fingerprint', function () {
    fingerprintTestSeller();
    test()->postJson('/api/v1/connections/woo/start', [
        'name' => 'Store A',
        'credentials' => ['store_url' => 'https://shared-shop.test', 'consumer_key' => 'ck_1', 'consumer_secret' => 'cs_1'],
    ])->assertCreated();

    fingerprintTestSeller();
    test()->postJson('/api/v1/connections/woo/start', [
        'name' => 'Store A again',
        'credentials' => ['store_url' => 'https://shared-shop.test', 'consumer_key' => 'ck_2', 'consumer_secret' => 'cs_2'],
    ])->assertCreated();

    $fingerprints = StoreConnection::query()->pluck('fingerprint')->unique();
    expect($fingerprints)->toHaveCount(1);
    expect(StoreConnection::query()->count())->toBe(2);
});
