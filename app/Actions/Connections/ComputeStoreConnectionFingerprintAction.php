<?php

namespace App\Actions\Connections;

/**
 * Plan §8.7.7 "trial-abuse fingerprint matches" — a stable identity for the
 * underlying store, independent of which team connected it, so the same
 * shop reconnected under a second free team is detectable. WooCommerce
 * (`credentials.store_url`) and Shopify (`credentials.shop_domain`) both
 * carry a real, stable store identity. eBay and Etsy don't expose one
 * without an extra API call/scope we don't have yet — a deliberate scope
 * cut, same as Amazon — so they return null. Returns null when no
 * recognizable identity is present, rather than fingerprinting on
 * team/name (which would defeat the point).
 */
class ComputeStoreConnectionFingerprintAction
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function handle(string $platform, array $credentials): ?string
    {
        $identity = $credentials['store_url'] ?? $credentials['shop_domain'] ?? null;

        if (! is_string($identity) || $identity === '') {
            return null;
        }

        $normalized = strtolower(rtrim($identity, '/'));

        return hash('sha256', "{$platform}|{$normalized}");
    }
}
