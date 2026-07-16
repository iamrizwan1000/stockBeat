<?php

namespace App\Actions\Connections;

/**
 * Plan §8.7.7 "trial-abuse fingerprint matches" — a stable identity for the
 * underlying store, independent of which team connected it, so the same
 * shop reconnected under a second free team is detectable. Only
 * WooCommerce carries a real store identity today (`credentials.store_url`)
 * — Shopify/eBay/Etsy/Amazon adapters are still stubs that throw before a
 * connection is ever created, so there's nothing to fingerprint for them
 * yet. Returns null when no recognizable identity is present, rather than
 * fingerprinting on team/name (which would defeat the point).
 */
class ComputeStoreConnectionFingerprintAction
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function handle(string $platform, array $credentials): ?string
    {
        $storeUrl = $credentials['store_url'] ?? null;

        if (! is_string($storeUrl) || $storeUrl === '') {
            return null;
        }

        $normalized = strtolower(rtrim($storeUrl, '/'));

        return hash('sha256', "{$platform}|{$normalized}");
    }
}
