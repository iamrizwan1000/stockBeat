<?php

namespace App\Support\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Shared id_token verification for Apple/Google (Plan §4.1) — both providers
 * issue standard signature-verifiable JWTs against a published JWKS,
 * differing only in issuer, audience, and how `email_verified` is encoded.
 * Never exposes *why* verification failed to the client (invalid signature,
 * wrong audience, expired, malformed — all collapse to the same message),
 * mirroring how OTP verification doesn't distinguish failure reasons either.
 */
abstract class JwtIdTokenVerifier implements SocialTokenVerifier
{
    abstract protected function jwksUrl(): string;

    /**
     * @return array<int, string> acceptable `iss` values — an array, not a
     *                            single string, because Google issues tokens with either
     *                            `https://accounts.google.com` or `accounts.google.com` depending on
     *                            token type, and both are valid.
     */
    abstract protected function issuers(): array;

    abstract protected function audience(): ?string;

    abstract protected function providerName(): string;

    /**
     * Apple sends `email_verified` as a string ("true"/"false") on tokens
     * from its native SDKs but a real boolean on web ones; Google always
     * sends a boolean. Left per-provider so each quirk is documented where
     * it's handled, rather than one shared "loose" cast hiding both.
     */
    abstract protected function isEmailVerified(object $claims): bool;

    public function verify(string $idToken): SocialIdentity
    {
        $audience = $this->audience();

        if ($audience === null || $audience === '') {
            throw ValidationException::withMessages([
                'provider' => "Sign in with {$this->providerName()} isn't configured yet.",
            ]);
        }

        $keys = Cache::remember(
            "social-auth.jwks.{$this->providerName()}",
            now()->addDay(),
            fn () => Http::get($this->jwksUrl())->throw()->json(),
        );

        try {
            $claims = JWT::decode($idToken, JWK::parseKeySet($keys));
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'id_token' => 'This sign-in token is invalid or has expired.',
            ]);
        }

        if (! in_array($claims->iss ?? null, $this->issuers(), true) || ($claims->aud ?? null) !== $audience) {
            throw ValidationException::withMessages([
                'id_token' => 'This sign-in token is invalid or has expired.',
            ]);
        }

        $email = $claims->email ?? null;

        if (! is_string($email) || $email === '') {
            throw ValidationException::withMessages([
                'id_token' => 'This sign-in token did not include an email address.',
            ]);
        }

        return new SocialIdentity(
            subject: (string) ($claims->sub ?? ''),
            email: $email,
            emailVerified: $this->isEmailVerified($claims),
        );
    }
}
