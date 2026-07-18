<?php

use App\Models\StoreConnection;
use App\Models\User;
use App\Support\Connections\OAuthState;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    config([
        'services.tiktok_shop.app_key' => 'test-app-key',
        'services.tiktok_shop.app_secret' => 'test-app-secret',
    ]);
});

function onboardedTikTokUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['tiktok']])->assertOk();

    return $user->fresh();
}

function fakeTikTokShopsAndWebhooksHttp(): void
{
    Http::fake([
        'open-api.tiktokglobalshop.com/authorization/202309/shops*' => Http::response([
            'data' => ['shops' => [['id' => 'shop-1', 'cipher' => 'cipher-abc']]],
        ], 200),
        'open-api.tiktokglobalshop.com/event/202309/webhooks*' => Http::response(['data' => []], 200),
    ]);
}

test('starting a tiktok connection returns a partner center authorization url', function () {
    onboardedTikTokUser();

    $response = test()->postJson('/api/v1/connections/tiktok/start', [
        'name' => 'My TikTok Shop',
        'credentials' => [],
    ])->assertOk();

    $url = $response->json('data.authorization_url');
    expect($url)->toStartWith('https://auth.tiktok-shops.com/oauth/authorize?');
    expect($url)->toContain('app_key=test-app-key');
});

test('a valid callback with a code completes the connection with tokens', function () {
    onboardedTikTokUser();
    fakeTikTokShopsAndWebhooksHttp();
    Http::fake([
        'auth.tiktok-shops.com/api/v2/token/get' => Http::response([
            'data' => [
                'access_token' => 'tta-fake-access',
                'refresh_token' => 'ttr-fake-refresh',
                'access_token_expire_in' => 7200,
                'open_id' => 'open-1',
                'seller_name' => 'Jamie\'s Shop',
            ],
        ], 200),
        'open-api.tiktokglobalshop.com/authorization/202309/shops*' => Http::response([
            'data' => ['shops' => [['id' => 'shop-1', 'cipher' => 'cipher-abc']]],
        ], 200),
        'open-api.tiktokglobalshop.com/event/202309/webhooks*' => Http::response(['data' => []], 200),
    ]);

    $authUrl = test()->postJson('/api/v1/connections/tiktok/start', [
        'name' => 'My TikTok Shop',
        'credentials' => [],
    ])->json('data.authorization_url');

    parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $startParams);

    test()->get('/hooks/tiktok/oauth/callback?'.http_build_query([
        'code' => 'fake-auth-code',
        'state' => $startParams['state'],
    ]))->assertOk();

    $connection = StoreConnection::query()->where('platform', StoreConnection::PLATFORM_TIKTOK)->first();
    expect($connection)->not->toBeNull();
    expect($connection->credentials['access_token'])->toBe('tta-fake-access');
    expect($connection->credentials['refresh_token'])->toBe('ttr-fake-refresh');
    expect($connection->credentials['shop_id'])->toBe('shop-1');
    expect($connection->credentials['shop_cipher'])->toBe('cipher-abc');
    expect($connection->status)->toBe(StoreConnection::STATUS_ACTIVE);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v2/token/get')
        && ($request['grant_type'] ?? null) === 'authorized_code'
        && ($request['auth_code'] ?? null) === 'fake-auth-code');
});

test('a callback with no code is rejected and no connection is created', function () {
    onboardedTikTokUser();

    $state = OAuthState::make(1, 'x', 'tiktok', [])->encode();

    test()->get('/hooks/tiktok/oauth/callback?'.http_build_query(['state' => $state]))->assertOk();

    expect(StoreConnection::query()->count())->toBe(0);
});

test('a failed token exchange does not create a connection', function () {
    onboardedTikTokUser();
    Http::fake(['auth.tiktok-shops.com/api/v2/token/get' => Http::response(['message' => 'invalid auth_code'], 400)]);

    $authUrl = test()->postJson('/api/v1/connections/tiktok/start', [
        'name' => 'My TikTok Shop',
        'credentials' => [],
    ])->json('data.authorization_url');

    parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $startParams);

    test()->get('/hooks/tiktok/oauth/callback?'.http_build_query([
        'code' => 'bad-code',
        'state' => $startParams['state'],
    ]))->assertOk();

    expect(StoreConnection::query()->count())->toBe(0);
});
