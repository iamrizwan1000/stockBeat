<?php

namespace App\Actions\Connections;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Contracts\OAuthChannelAdapter;
use App\Models\PaywallHit;
use App\Models\PlanLimit;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Support\Connections\ChannelAdapterManager;
use App\Support\Connections\OAuthState;
use Illuminate\Validation\ValidationException;

/**
 * Starts an OAuth-based connection (Plan §7: Shopify/eBay/Etsy) — the
 * `max_stores` check happens here, upfront, exactly like
 * `ConnectStoreAction`, rather than being deferred to the callback where a
 * merchant could complete a real OAuth grant only to be rejected after
 * the fact. Callers must only invoke this for a platform whose adapter
 * implements `OAuthChannelAdapter` (`ConnectionController::start()` checks
 * this before choosing between this action and `ConnectStoreAction`).
 */
class StartOAuthConnectionAction
{
    public function __construct(
        private readonly ChannelAdapterManager $adapters,
        private readonly ResolveEntitlementsAction $resolveEntitlements,
        private readonly ComputeStoreConnectionFingerprintAction $fingerprintAction,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function handle(Team $team, string $platform, string $name, array $credentials): string
    {
        $maxStores = $this->resolveEntitlements->handle($team)['limits']['max_stores'] ?? null;

        if ($maxStores !== null) {
            $query = StoreConnection::query()->where('team_id', $team->id);

            // A reconnect (Fix-it/Reconnect tap on an existing needs_reauth
            // or disconnected row) must not count against the same team's
            // own store slot it's already occupying — without this
            // exclusion, a team sitting exactly at `max_stores` could never
            // reconnect its own store again, which is the single most
            // common shape of this call (a free-tier team with exactly one
            // store that just needs reauth).
            $fingerprint = $this->fingerprintAction->handle($platform, $credentials);

            if ($fingerprint !== null) {
                $query->where(function ($q) use ($fingerprint) {
                    $q->whereNull('fingerprint')->orWhere('fingerprint', '!=', $fingerprint);
                });
            }

            if ($query->count() >= $maxStores) {
                PaywallHit::log($team, PlanLimit::MAX_STORES);

                throw ValidationException::withMessages([
                    'platform' => "You've reached your plan's store limit ({$maxStores}). Upgrade to connect more stores.",
                ]);
            }
        }

        /** @var OAuthChannelAdapter $adapter */
        $adapter = $this->adapters->driver($platform);

        $state = OAuthState::make($team->id, $name, $platform, $credentials);

        return $adapter->authorizationUrl($credentials, $state->encode());
    }
}
