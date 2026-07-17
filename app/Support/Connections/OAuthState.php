<?php

namespace App\Support\Connections;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Str;

/**
 * The `state` query param round-tripped through an OAuth authorization
 * flow (Plan §7's Shopify/eBay/Etsy). Laravel's `encrypt()` gives us
 * tamper-detection for free (AEAD — any modification fails decryption),
 * which is exactly what `state` needs to double as: a CSRF guard and the
 * only way the callback recovers which team/name this connection is for,
 * since the callback route is public and unauthenticated.
 */
final readonly class OAuthState
{
    /**
     * @param  array<string, mixed>  $credentials  Whatever was submitted to
     *                                             `POST /connections/{platform}/start` (e.g. Shopify's shop_domain).
     */
    public function __construct(
        public int $teamId,
        public string $name,
        public string $platform,
        public array $credentials,
        public string $nonce,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function make(int $teamId, string $name, string $platform, array $credentials): self
    {
        return new self($teamId, $name, $platform, $credentials, Str::random(32));
    }

    public function encode(): string
    {
        return encrypt([
            'team_id' => $this->teamId,
            'name' => $this->name,
            'platform' => $this->platform,
            'credentials' => $this->credentials,
            'nonce' => $this->nonce,
        ]);
    }

    public static function decode(string $state): ?self
    {
        try {
            $data = decrypt($state);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ! isset($data['team_id'], $data['name'], $data['platform'], $data['nonce'])) {
            return null;
        }

        return new self(
            (int) $data['team_id'],
            (string) $data['name'],
            (string) $data['platform'],
            is_array($data['credentials'] ?? null) ? $data['credentials'] : [],
            (string) $data['nonce'],
        );
    }
}
