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
        'services.amazon.client_id' => 'test-lwa-client-id',
        'services.amazon.client_secret' => 'test-lwa-client-secret',
        'services.amazon.app_id' => 'test-spapi-app-id',
        'services.amazon.aws_access_key_id' => 'AKIAFAKE',
        'services.amazon.aws_secret_access_key' => 'fake-secret',
        'services.amazon.aws_region' => 'us-east-1',
        'services.amazon.role_arn' => 'arn:aws:iam::123456789012:role/spapi',
    ]);
});

function onboardedAmazonUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['amazon']])->assertOk();

    return $user->fresh();
}

test('starting an amazon connection returns a seller central consent url', function () {
    onboardedAmazonUser();

    $response = test()->postJson('/api/v1/connections/amazon/start', [
        'name' => 'My Amazon Store',
        'credentials' => [],
    ])->assertOk();

    $url = $response->json('data.authorization_url');
    expect($url)->toStartWith('https://sellercentral.amazon.com/apps/authorize/consent?');
    expect($url)->toContain('application_id=test-spapi-app-id');
    expect($url)->toContain('version=beta');
});

test('a valid callback with spapi_oauth_code completes the connection with tokens', function () {
    onboardedAmazonUser();
    Http::fake([
        'api.amazon.com/auth/o2/token' => Http::response([
            'access_token' => 'Atza|fake-access',
            'refresh_token' => 'Atzr|fake-refresh',
            'expires_in' => 3600,
        ], 200),
    ]);

    $authUrl = test()->postJson('/api/v1/connections/amazon/start', [
        'name' => 'My Amazon Store',
        'credentials' => [],
    ])->json('data.authorization_url');

    parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $startParams);

    test()->get('/hooks/amazon/oauth/callback?'.http_build_query([
        'spapi_oauth_code' => 'fake-auth-code',
        'selling_partner_id' => 'A1B2C3D4E5',
        'state' => $startParams['state'],
    ]))->assertOk();

    $connection = StoreConnection::query()->where('platform', StoreConnection::PLATFORM_AMAZON)->first();
    expect($connection)->not->toBeNull();
    expect($connection->credentials['access_token'])->toBe('Atza|fake-access');
    expect($connection->credentials['refresh_token'])->toBe('Atzr|fake-refresh');
    expect($connection->credentials['selling_partner_id'])->toBe('A1B2C3D4E5');
    expect($connection->status)->toBe(StoreConnection::STATUS_ACTIVE);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/auth/o2/token')
        && ($request['grant_type'] ?? null) === 'authorization_code'
        && ($request['code'] ?? null) === 'fake-auth-code');
});

test('a callback with no spapi_oauth_code is rejected and no connection is created', function () {
    onboardedAmazonUser();

    $state = OAuthState::make(1, 'x', 'amazon', [])->encode();

    test()->get('/hooks/amazon/oauth/callback?'.http_build_query(['state' => $state]))->assertOk();

    expect(StoreConnection::query()->count())->toBe(0);
});

test('a failed token exchange does not create a connection', function () {
    onboardedAmazonUser();
    Http::fake(['api.amazon.com/auth/o2/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $authUrl = test()->postJson('/api/v1/connections/amazon/start', [
        'name' => 'My Amazon Store',
        'credentials' => [],
    ])->json('data.authorization_url');

    parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $startParams);

    test()->get('/hooks/amazon/oauth/callback?'.http_build_query([
        'spapi_oauth_code' => 'bad-code',
        'state' => $startParams['state'],
    ]))->assertOk();

    expect(StoreConnection::query()->count())->toBe(0);
});
