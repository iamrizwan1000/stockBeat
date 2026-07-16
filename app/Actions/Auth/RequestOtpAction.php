<?php

namespace App\Actions\Auth;

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Issues a fresh 6-digit OTP for the given email and mails it, per Plan §4.1.
 * Always succeeds regardless of whether the email belongs to an existing
 * user — account existence is never revealed at this step.
 */
class RequestOtpAction
{
    private const RESEND_COOLDOWN_SECONDS = 30;

    private const CODE_EXPIRY_MINUTES = 10;

    public function handle(string $email, ?string $ip): void
    {
        $lastCode = OtpCode::query()
            ->where('email', $email)
            ->latest('id')
            ->first();

        if ($lastCode && now()->lt($lastCode->created_at->clone()->addSeconds(self::RESEND_COOLDOWN_SECONDS))) {
            throw ValidationException::withMessages([
                'email' => 'Please wait a moment before requesting another code.',
            ]);
        }

        OtpCode::query()
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = (string) random_int(100000, 999999);

        OtpCode::query()->create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_EXPIRY_MINUTES),
            'ip' => $ip,
        ]);

        Mail::to($email)->queue(new OtpCodeMail($code));
    }
}
