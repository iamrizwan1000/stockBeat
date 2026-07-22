<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Auth\AppleIdTokenVerifier;
use App\Support\Auth\GoogleIdTokenVerifier;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Verifies an Apple/Google id_token and issues a device token, per Plan
 * §4.1/§17.1. A *returning* social user is matched by their stable provider
 * subject id first; a first-time social sign-in then converges onto any
 * existing account by verified email (e.g. someone who signed up via OTP
 * once and taps "Continue with Apple" later lands on the same account,
 * never a second one) — same convergence rule VerifyOtpAction's sibling
 * OTP flow relies on, just keyed by provider identity instead of a code.
 */
class VerifySocialSignInAction
{
    public function __construct(
        private readonly AppleIdTokenVerifier $appleVerifier,
        private readonly GoogleIdTokenVerifier $googleVerifier,
    ) {}

    /**
     * @return array{token: string, is_new_user: bool, user: User}
     */
    public function handle(string $provider, string $idToken, string $deviceName, ?string $ip = null): array
    {
        $identity = match ($provider) {
            'apple' => $this->appleVerifier->verify($idToken),
            'google' => $this->googleVerifier->verify($idToken),
            default => throw ValidationException::withMessages([
                'provider' => 'Unsupported sign-in provider.',
            ]),
        };

        if (! $identity->emailVerified) {
            throw ValidationException::withMessages([
                'id_token' => 'This account\'s email address is not verified.',
            ]);
        }

        $subjectColumn = $provider === 'apple' ? 'apple_sub' : 'google_sub';

        $user = User::query()->where($subjectColumn, $identity->subject)->first();

        if ($user === null) {
            // The user's own soft-delete excludes them from the default
            // query, so without this check firstOrNew() below would treat
            // them as brand new and crash on the still-occupied unique
            // email column — mirrors VerifyOtpAction's identical guard.
            if (User::onlyTrashed()->where('email', $identity->email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This account has been deleted. Contact support if you believe this is a mistake.',
                ]);
            }

            $user = User::query()->firstOrNew(['email' => $identity->email]);
        }

        $isNewUser = ! $user->exists;

        if ($isNewUser) {
            $user->name = '';
            $user->base_currency = 'USD';
            $user->signup_ip = $ip;
        }

        $user->{$subjectColumn} = $identity->subject;
        $user->last_active_at = Carbon::now();
        $user->save();

        $token = $user->createToken($deviceName)->plainTextToken;

        return [
            'token' => $token,
            'is_new_user' => $isNewUser,
            'user' => $user,
        ];
    }
}
