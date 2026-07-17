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
        'services.ebay.env' => 'sandbox',
        'services.ebay.app_id' => 'test-app-id',
        'services.ebay.cert_id' => 'test-cert-id',
        'services.ebay.ru_name' => 'test-ru-name',
    ]);
});

function onboardedEbayUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['ebay']])->assertOk();

    return $user->fresh();
}

test('starting an ebay connection returns a sandbox authorization url', function () {
    onboardedEbayUser();

    $response = test()->postJson('/api/v1/connections/ebay/start', [
        'name' => 'My eBay Store',
        'credentials' => [],
    ])->assertOk();

    $url = $response->json('data.authorization_url');
    expect($url)->toStartWith('https://auth.sandbox.ebay.com/oauth2/authorize?');
    expect($url)->toContain('client_id=test-app-id');
    expect($url)->toContain('redirect_uri=test-ru-name');
});

test('a valid callback completes the connection with tokens', function () {
    onboardedEbayUser();
    Http::fake([
        'api.sandbox.ebay.com/identity/v1/oauth2/token' => Http::response([
            'access_token' => 'v^1.1#fake-access',
            'refresh_token' => 'v^1.1#fake-refresh',
            'expires_in' => 7200,
        ], 200),
    ]);

    $authUrl = test()->postJson('/api/v1/connections/ebay/start', [
        'name' => 'My eBay Store',
        'credentials' => [],
    ])->json('data.authorization_url');

    parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $startParams);

    test()->get('/hooks/ebay/oauth/callback?'.http_build_query([
        'code' => 'fake-auth-code',
        'state' => $startParams['state'],
    ]))->assertOk();

    $connection = StoreConnection::query()->where('platform', StoreConnection::PLATFORM_EBAY)->first();
    expect($connection)->not->toBeNull();
    expect($connection->credentials['access_token'])->toBe('v^1.1#fake-access');
    expect($connection->credentials['refresh_token'])->toBe('v^1.1#fake-refresh');
    expect($connection->status)->toBe(StoreConnection::STATUS_ACTIVE);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/identity/v1/oauth2/token')
        && ($request['grant_type'] ?? null) === 'authorization_code'
        && ($request['redirect_uri'] ?? null) === 'test-ru-name');
});

test('a callback with no code is rejected and no connection is created', function () {
    onboardedEbayUser();

    $state = OAuthState::make(1, 'x', 'ebay', [])->encode();

    test()->get('/hooks/ebay/oauth/callback?'.http_build_query(['state' => $state]))->assertOk();

    expect(StoreConnection::query()->count())->toBe(0);
});

test('a failed token exchange does not create a connection', function () {
    onboardedEbayUser();
    Http::fake(['api.sandbox.ebay.com/identity/v1/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $authUrl = test()->postJson('/api/v1/connections/ebay/start', [
        'name' => 'My eBay Store',
        'credentials' => [],
    ])->json('data.authorization_url');

    parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $startParams);

    test()->get('/hooks/ebay/oauth/callback?'.http_build_query([
        'code' => 'bad-code',
        'state' => $startParams['state'],
    ]))->assertOk();

    expect(StoreConnection::query()->count())->toBe(0);
});
