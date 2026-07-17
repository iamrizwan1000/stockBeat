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
    config(['services.etsy.keystring' => 'test-keystring']);
});

function onboardedEtsyUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['etsy']])->assertOk();

    return $user->fresh();
}

test('starting an etsy connection returns an authorization url with a PKCE challenge', function () {
    onboardedEtsyUser();

    $response = test()->postJson('/api/v1/connections/etsy/start', [
        'name' => 'My Etsy Shop',
        'credentials' => [],
    ])->assertOk();

    $url = $response->json('data.authorization_url');
    expect($url)->toStartWith('https://www.etsy.com/oauth/connect?');
    expect($url)->toContain('client_id=test-keystring');
    expect($url)->toContain('code_challenge_method=S256');
    expect($url)->toContain('code_challenge=');
});

test('a valid callback exchanges the code, resolves the shop, and completes the connection', function () {
    onboardedEtsyUser();
    Http::fake([
        'api.etsy.com/v3/public/oauth/token' => Http::response([
            'access_token' => '98765.fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_in' => 3600,
        ], 200),
        'api.etsy.com/v3/application/users/98765/shops' => Http::response(['shop_id' => 555111], 200),
    ]);

    $authUrl = test()->postJson('/api/v1/connections/etsy/start', [
        'name' => 'My Etsy Shop',
        'credentials' => [],
    ])->json('data.authorization_url');

    parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $startParams);

    test()->get('/hooks/etsy/oauth/callback?'.http_build_query([
        'code' => 'fake-auth-code',
        'state' => $startParams['state'],
    ]))->assertOk();

    $connection = StoreConnection::query()->where('platform', StoreConnection::PLATFORM_ETSY)->first();
    expect($connection)->not->toBeNull();
    expect($connection->credentials['access_token'])->toBe('98765.fake-access-token');
    expect($connection->credentials['shop_id'])->toBe(555111);
    expect($connection->status)->toBe(StoreConnection::STATUS_ACTIVE);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth/token')
        && ($request['code_verifier'] ?? null) !== null
        && ($request['grant_type'] ?? null) === 'authorization_code');
});

test('the same nonce always derives the same code_verifier across both OAuth steps', function () {
    onboardedEtsyUser();

    $capturedChallenge = null;
    $capturedVerifier = null;

    Http::fake(function ($request) use (&$capturedVerifier) {
        if (str_contains($request->url(), '/oauth/token')) {
            $capturedVerifier = $request['code_verifier'] ?? null;

            return Http::response(['access_token' => '1.tok', 'refresh_token' => 'r', 'expires_in' => 3600], 200);
        }

        return Http::response(['shop_id' => 1], 200);
    });

    $authUrl = test()->postJson('/api/v1/connections/etsy/start', [
        'name' => 'My Etsy Shop',
        'credentials' => [],
    ])->json('data.authorization_url');

    parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $startParams);
    $capturedChallenge = $startParams['code_challenge'];

    test()->get('/hooks/etsy/oauth/callback?'.http_build_query([
        'code' => 'fake-auth-code',
        'state' => $startParams['state'],
    ]))->assertOk();

    $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $capturedVerifier, true)), '+/', '-_'), '=');
    expect($capturedChallenge)->toBe($expectedChallenge);
});

test('a callback with no code is rejected and no connection is created', function () {
    onboardedEtsyUser();

    $state = OAuthState::make(1, 'x', 'etsy', [])->encode();

    test()->get('/hooks/etsy/oauth/callback?'.http_build_query(['state' => $state]))->assertOk();

    expect(StoreConnection::query()->count())->toBe(0);
});

test('a failed shop lookup does not create a connection', function () {
    onboardedEtsyUser();
    Http::fake([
        'api.etsy.com/v3/public/oauth/token' => Http::response(['access_token' => '1.tok', 'refresh_token' => 'r', 'expires_in' => 3600], 200),
        'api.etsy.com/v3/application/users/1/shops' => Http::response([], 500),
    ]);

    $authUrl = test()->postJson('/api/v1/connections/etsy/start', [
        'name' => 'My Etsy Shop',
        'credentials' => [],
    ])->json('data.authorization_url');

    parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $startParams);

    test()->get('/hooks/etsy/oauth/callback?'.http_build_query([
        'code' => 'fake-auth-code',
        'state' => $startParams['state'],
    ]))->assertOk();

    expect(StoreConnection::query()->count())->toBe(0);
});
