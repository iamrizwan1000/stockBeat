<?php

namespace App\Support\Auth;

/**
 * The verified claims pulled out of an Apple/Google id_token (Plan §4.1),
 * after signature/issuer/audience/expiry checks have already passed —
 * everything downstream (VerifySocialSignInAction) trusts these values.
 */
final readonly class SocialIdentity
{
    public function __construct(
        public string $subject,
        public string $email,
        public bool $emailVerified,
    ) {}
}
