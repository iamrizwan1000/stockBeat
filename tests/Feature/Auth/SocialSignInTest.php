<?php

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{private: string, n: string, e: string}
 */
function generateRsaKeyPair(): array
{
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($resource, $privateKeyPem);
    $details = openssl_pkey_get_details($resource);

    return [
        'private' => $privateKeyPem,
        'n' => $details['rsa']['n'],
        'e' => $details['rsa']['e'],
    ];
}

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * @param  array{private: string, n: string, e: string}  $keyPair
 */
function fakeSocialJwks(string $provider, array $keyPair, string $kid = 'test-kid'): void
{
    $jwksUrl = $provider === 'apple'
        ? 'https://appleid.apple.com/auth/keys'
        : 'https://www.googleapis.com/oauth2/v3/certs';

    Http::fake([
        $jwksUrl => Http::response(['keys' => [[
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => base64UrlEncode($keyPair['n']),
            'e' => base64UrlEncode($keyPair['e']),
        ]]]),
    ]);
}

/**
 * @param  array{private: string, n: string, e: string}  $keyPair
 * @param  array<string, mixed>  $claimOverrides
 */
function signSocialToken(string $provider, array $keyPair, array $claimOverrides = [], string $kid = 'test-kid'): string
{
    $issuer = $provider === 'apple' ? 'https://appleid.apple.com' : 'https://accounts.google.com';

    $claims = array_merge([
        'iss' => $issuer,
        'aud' => $provider === 'apple' ? 'apple-client-id' : 'google-client-id',
        'sub' => 'provider-subject-'.$provider,
        'email' => 'seller@example.com',
        'email_verified' => $provider === 'apple' ? 'true' : true,
        'iat' => time(),
        'exp' => time() + 3600,
    ], $claimOverrides);

    return JWT::encode($claims, $keyPair['private'], 'RS256', $kid);
}

beforeEach(function () {
    config(['services.apple.client_id' => 'apple-client-id']);
    config(['services.google.client_id' => 'google-client-id']);
    Cache::flush();
});

test('signing in with a valid apple id_token creates a new user and issues a token', function () {
    $keyPair = generateRsaKeyPair();
    fakeSocialJwks('apple', $keyPair);
    $token = signSocialToken('apple', $keyPair, ['email' => 'new-apple-user@example.com']);

    $response = test()->postJson('/api/v1/auth/social', [
        'provider' => 'apple',
        'id_token' => $token,
    ]);

    $response->assertOk()->assertJsonPath('data.is_new_user', true);
    $response->assertJsonStructure(['data' => ['token', 'is_new_user', 'user' => ['id', 'email']]]);

    $user = User::query()->where('email', 'new-apple-user@example.com')->firstOrFail();
    expect($user->apple_sub)->toBe('provider-subject-apple');
});

test('signing in with a valid google id_token creates a new user and issues a token', function () {
    $keyPair = generateRsaKeyPair();
    fakeSocialJwks('google', $keyPair);
    $token = signSocialToken('google', $keyPair, ['email' => 'new-google-user@example.com']);

    $response = test()->postJson('/api/v1/auth/social', [
        'provider' => 'google',
        'id_token' => $token,
    ]);

    $response->assertOk()->assertJsonPath('data.is_new_user', true);

    $user = User::query()->where('email', 'new-google-user@example.com')->firstOrFail();
    expect($user->google_sub)->toBe('provider-subject-google');
});

test('a returning social user is matched by subject id, not treated as new', function () {
    $keyPair = generateRsaKeyPair();
    fakeSocialJwks('apple', $keyPair);

    $user = User::factory()->create(['apple_sub' => 'provider-subject-apple', 'email' => 'existing@example.com']);
    $token = signSocialToken('apple', $keyPair, ['email' => 'existing@example.com']);

    $response = test()->postJson('/api/v1/auth/social', [
        'provider' => 'apple',
        'id_token' => $token,
    ]);

    $response->assertOk()->assertJsonPath('data.is_new_user', false);
    expect(User::query()->where('email', 'existing@example.com')->count())->toBe(1);
});

test('a first-time social sign-in converges onto an existing account with the same verified email, not a duplicate', function () {
    $keyPair = generateRsaKeyPair();
    fakeSocialJwks('apple', $keyPair);

    $existing = User::factory()->create(['email' => 'otp-user@example.com', 'apple_sub' => null]);
    $token = signSocialToken('apple', $keyPair, ['email' => 'otp-user@example.com']);

    $response = test()->postJson('/api/v1/auth/social', [
        'provider' => 'apple',
        'id_token' => $token,
    ]);

    $response->assertOk()->assertJsonPath('data.is_new_user', false);
    expect(User::query()->where('email', 'otp-user@example.com')->count())->toBe(1);
    expect($existing->fresh()->apple_sub)->toBe('provider-subject-apple');
});

test('a token with an unverified email is rejected', function () {
    $keyPair = generateRsaKeyPair();
    fakeSocialJwks('google', $keyPair);
    $token = signSocialToken('google', $keyPair, ['email_verified' => false]);

    test()->postJson('/api/v1/auth/social', [
        'provider' => 'google',
        'id_token' => $token,
    ])->assertUnprocessable()->assertJsonValidationErrors('id_token');
});

test('a token signed with the wrong key is rejected', function () {
    $realKeyPair = generateRsaKeyPair();
    $attackerKeyPair = generateRsaKeyPair();
    fakeSocialJwks('apple', $realKeyPair);

    $token = signSocialToken('apple', $attackerKeyPair);

    test()->postJson('/api/v1/auth/social', [
        'provider' => 'apple',
        'id_token' => $token,
    ])->assertUnprocessable()->assertJsonValidationErrors('id_token');
});

test('a token with the wrong audience is rejected', function () {
    $keyPair = generateRsaKeyPair();
    fakeSocialJwks('apple', $keyPair);
    $token = signSocialToken('apple', $keyPair, ['aud' => 'someone-elses-app']);

    test()->postJson('/api/v1/auth/social', [
        'provider' => 'apple',
        'id_token' => $token,
    ])->assertUnprocessable()->assertJsonValidationErrors('id_token');
});

test('a token with the wrong issuer is rejected', function () {
    $keyPair = generateRsaKeyPair();
    fakeSocialJwks('apple', $keyPair);
    $token = signSocialToken('apple', $keyPair, ['iss' => 'https://evil.example.com']);

    test()->postJson('/api/v1/auth/social', [
        'provider' => 'apple',
        'id_token' => $token,
    ])->assertUnprocessable()->assertJsonValidationErrors('id_token');
});

test('an expired token is rejected', function () {
    $keyPair = generateRsaKeyPair();
    fakeSocialJwks('apple', $keyPair);
    $token = signSocialToken('apple', $keyPair, ['exp' => time() - 60]);

    test()->postJson('/api/v1/auth/social', [
        'provider' => 'apple',
        'id_token' => $token,
    ])->assertUnprocessable()->assertJsonValidationErrors('id_token');
});

test('google accepts the accounts.google.com issuer without the https scheme', function () {
    $keyPair = generateRsaKeyPair();
    fakeSocialJwks('google', $keyPair);
    $token = signSocialToken('google', $keyPair, ['iss' => 'accounts.google.com']);

    test()->postJson('/api/v1/auth/social', [
        'provider' => 'google',
        'id_token' => $token,
    ])->assertOk();
});

test('signing in when the provider is not configured fails cleanly', function () {
    config(['services.apple.client_id' => null]);

    $keyPair = generateRsaKeyPair();
    $token = signSocialToken('apple', $keyPair);

    test()->postJson('/api/v1/auth/social', [
        'provider' => 'apple',
        'id_token' => $token,
    ])->assertUnprocessable()->assertJsonValidationErrors('provider');
});

test('an unsupported provider is rejected by validation', function () {
    test()->postJson('/api/v1/auth/social', [
        'provider' => 'facebook',
        'id_token' => 'whatever',
    ])->assertUnprocessable()->assertJsonValidationErrors('provider');
});

test('social sign-in is rate limited per ip', function () {
    $keyPair = generateRsaKeyPair();
    fakeSocialJwks('apple', $keyPair);

    foreach (range(1, 10) as $attempt) {
        $token = signSocialToken('apple', $keyPair, ['email' => "user{$attempt}@example.com"]);
        test()->postJson('/api/v1/auth/social', ['provider' => 'apple', 'id_token' => $token])->assertOk();
    }

    $token = signSocialToken('apple', $keyPair, ['email' => 'one-too-many@example.com']);
    test()->postJson('/api/v1/auth/social', ['provider' => 'apple', 'id_token' => $token])
        ->assertStatus(429);
});
