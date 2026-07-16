<?php

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function requestOtp(string $email = 'seller@example.com'): TestResponse
{
    return test()->postJson('/api/v1/auth/otp/request', ['email' => $email]);
}

function capturedOtpCode(string $email): string
{
    $code = null;

    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return $code;
}

beforeEach(function () {
    Mail::fake();
});

test('requesting an otp always returns ok, whether or not the email exists', function () {
    requestOtp('brand-new@example.com')->assertOk()->assertJson(['success' => true]);

    $user = User::factory()->create();
    requestOtp($user->email)->assertOk()->assertJson(['success' => true]);

    expect(User::query()->where('email', 'brand-new@example.com')->exists())->toBeFalse();
});

test('requesting an otp mails a 6-digit code', function () {
    requestOtp();

    $code = capturedOtpCode('seller@example.com');

    expect($code)->toMatch('/^\d{6}$/');

    $stored = OtpCode::query()->where('email', 'seller@example.com')->sole();
    expect(Hash::check($code, $stored->code_hash))->toBeTrue();
});

test('verifying a correct code creates a new user and issues a token', function () {
    requestOtp('new-seller@example.com');
    $code = capturedOtpCode('new-seller@example.com');

    $response = test()->postJson('/api/v1/auth/otp/verify', [
        'email' => 'new-seller@example.com',
        'code' => $code,
    ]);

    $response->assertOk()->assertJsonPath('data.is_new_user', true);
    $response->assertJsonStructure(['data' => ['token', 'is_new_user', 'user' => ['id', 'email']]]);

    expect(User::query()->where('email', 'new-seller@example.com')->exists())->toBeTrue();
});

test('verifying a correct code for an existing user does not create a duplicate', function () {
    $user = User::factory()->create();
    requestOtp($user->email);
    $code = capturedOtpCode($user->email);

    $response = test()->postJson('/api/v1/auth/otp/verify', [
        'email' => $user->email,
        'code' => $code,
    ]);

    $response->assertOk()->assertJsonPath('data.is_new_user', false);
    expect(User::query()->where('email', $user->email)->count())->toBe(1);
});

test('verifying with the wrong code fails and increments attempts', function () {
    requestOtp();
    capturedOtpCode('seller@example.com');

    test()->postJson('/api/v1/auth/otp/verify', [
        'email' => 'seller@example.com',
        'code' => '000000',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');

    expect(OtpCode::query()->where('email', 'seller@example.com')->sole()->attempts)->toBe(1);
});

test('a code is locked after 5 wrong attempts, even if the 6th is correct', function () {
    requestOtp();
    $code = capturedOtpCode('seller@example.com');

    foreach (range(1, 5) as $attempt) {
        test()->postJson('/api/v1/auth/otp/verify', [
            'email' => 'seller@example.com',
            'code' => '000000',
        ])->assertUnprocessable();
    }

    test()->postJson('/api/v1/auth/otp/verify', [
        'email' => 'seller@example.com',
        'code' => $code,
    ])->assertUnprocessable()->assertJsonValidationErrors('code');
});

test('an expired code cannot be verified', function () {
    requestOtp();
    $code = capturedOtpCode('seller@example.com');

    Carbon::setTestNow(now()->addMinutes(11));

    test()->postJson('/api/v1/auth/otp/verify', [
        'email' => 'seller@example.com',
        'code' => $code,
    ])->assertUnprocessable()->assertJsonValidationErrors('code');

    Carbon::setTestNow();
});

test('resending within the cooldown window is rejected, and a resend invalidates the previous code', function () {
    requestOtp();
    $firstCode = capturedOtpCode('seller@example.com');

    requestOtp()->assertUnprocessable()->assertJsonValidationErrors('email');

    Carbon::setTestNow(now()->addSeconds(31));
    requestOtp()->assertOk();
    $secondCode = capturedOtpCode('seller@example.com');

    test()->postJson('/api/v1/auth/otp/verify', [
        'email' => 'seller@example.com',
        'code' => $firstCode,
    ])->assertUnprocessable();

    test()->postJson('/api/v1/auth/otp/verify', [
        'email' => 'seller@example.com',
        'code' => $secondCode,
    ])->assertOk();

    Carbon::setTestNow();
});

test('otp requests are rate limited per email and ip', function () {
    RateLimiter::clear('otp-request');

    foreach (range(1, 3) as $attempt) {
        requestOtp('limited@example.com')->assertOk();
        Carbon::setTestNow(now()->addSeconds(31));
    }

    requestOtp('limited@example.com')->assertStatus(429);

    Carbon::setTestNow();
});
