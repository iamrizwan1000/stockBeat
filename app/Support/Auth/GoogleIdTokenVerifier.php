<?php

namespace App\Support\Auth;

class GoogleIdTokenVerifier extends JwtIdTokenVerifier
{
    protected function jwksUrl(): string
    {
        return 'https://www.googleapis.com/oauth2/v3/certs';
    }

    protected function issuers(): array
    {
        return ['https://accounts.google.com', 'accounts.google.com'];
    }

    protected function audience(): ?string
    {
        return config('services.google.client_id');
    }

    protected function providerName(): string
    {
        return 'google';
    }

    protected function isEmailVerified(object $claims): bool
    {
        return ($claims->email_verified ?? false) === true;
    }
}
