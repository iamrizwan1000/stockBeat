<?php

namespace App\Support\Auth;

use Illuminate\Validation\ValidationException;

interface SocialTokenVerifier
{
    /**
     * Verifies an id_token's signature, issuer, audience, and expiry, and
     * returns its verified identity claims.
     *
     * @throws ValidationException
     */
    public function verify(string $idToken): SocialIdentity;
}
