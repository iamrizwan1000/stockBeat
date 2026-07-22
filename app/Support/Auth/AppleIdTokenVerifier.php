<?php

namespace App\Support\Auth;

class AppleIdTokenVerifier extends JwtIdTokenVerifier
{
    protected function jwksUrl(): string
    {
        return 'https://appleid.apple.com/auth/keys';
    }

    protected function issuers(): array
    {
        return ['https://appleid.apple.com'];
    }

    protected function audience(): ?string
    {
        return config('services.apple.client_id');
    }

    protected function providerName(): string
    {
        return 'apple';
    }

    protected function isEmailVerified(object $claims): bool
    {
        $verified = $claims->email_verified ?? false;

        return $verified === true || $verified === 'true';
    }
}
