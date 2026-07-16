<?php

namespace App\Actions\Auth;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Verifies an OTP and issues a device token, per Plan §4.1. The user record
 * is created only here, on successful verification — never on request —
 * so a typo'd email never leaves an orphan account (Plan §17.1). Records the
 * requesting IP as `signup_ip` on new users only (Plan §8.7.7 trial-abuse
 * fingerprinting) — never overwritten on repeat logins.
 */
class VerifyOtpAction
{
    /**
     * @return array{token: string, is_new_user: bool, user: User}
     */
    public function handle(string $email, string $code, string $deviceName, ?string $ip = null): array
    {
        $otpCode = OtpCode::query()
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otpCode || $otpCode->isExpired()) {
            throw ValidationException::withMessages([
                'code' => 'This code is invalid or has expired.',
            ]);
        }

        if ($otpCode->isLocked()) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Please request a new code.',
            ]);
        }

        if (! Hash::check($code, $otpCode->code_hash)) {
            $otpCode->increment('attempts');

            throw ValidationException::withMessages([
                'code' => 'This code is incorrect.',
            ]);
        }

        $otpCode->forceFill(['consumed_at' => now()])->save();

        // The user's own soft-delete excludes them from the default query,
        // so without this check firstOrNew() below would treat them as
        // brand new and crash on the still-occupied unique email column.
        if (User::onlyTrashed()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This account has been deleted. Contact support if you believe this is a mistake.',
            ]);
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $isNewUser = ! $user->exists;

        if ($isNewUser) {
            $user->name = '';
            $user->base_currency = 'USD';
            $user->signup_ip = $ip;
        }

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
