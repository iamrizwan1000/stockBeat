<?php

namespace App\Contracts;

use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Http\Request;

/**
 * Implemented by adapters whose `connect()` can't be a single synchronous
 * call (Plan §7's OAuth-based platforms: Shopify, eBay, Etsy) — connecting
 * is a two-step round trip through the platform's own authorization page,
 * unlike WooCommerce's direct key-based `ChannelAdapter::connect()`.
 *
 * `ConnectionController::start()` checks `instanceof OAuthChannelAdapter`
 * to pick the right flow: OAuth platforms return an authorization URL
 * instead of creating a `StoreConnection` immediately.
 */
interface OAuthChannelAdapter
{
    /**
     * Builds the URL to redirect the merchant to. `$startCredentials` is
     * whatever the merchant submitted when starting the connection (e.g.
     * Shopify's `shop_domain` — eBay/Etsy need nothing extra here, their
     * app-level credentials come from config). `$state` is an opaque,
     * server-signed token (see `StartOAuthConnectionAction`) that must be
     * round-tripped back verbatim so the callback can recover which team
     * this connection belongs to.
     *
     * @param  array<string, mixed>  $startCredentials
     */
    public function authorizationUrl(array $startCredentials, string $state): string;

    /**
     * Completes the connection once the platform redirects back with a
     * `code` (and platform-specific params). `$startCredentials` is the
     * same array passed to `authorizationUrl()` — recovered from the
     * decoded state, not re-submitted by the merchant, so it can't be
     * tampered with between the two steps. `$nonce` is the same
     * `OAuthState` nonce round-tripped through `state` — Etsy's PKCE flow
     * derives its `code_verifier` from it (deterministically, so nothing
     * extra needs to be persisted between the two steps); Shopify/eBay
     * don't use PKCE and ignore it.
     *
     * @param  array<string, mixed>  $startCredentials
     */
    public function completeConnection(Team $team, string $name, array $startCredentials, string $nonce, Request $callback): StoreConnection;
}
