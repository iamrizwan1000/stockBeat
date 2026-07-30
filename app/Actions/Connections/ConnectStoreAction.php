<?php

namespace App\Actions\Connections;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Models\PaywallHit;
use App\Models\PlanLimit;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Support\Concurrency\IdempotencyGuard;
use App\Support\Connections\ChannelAdapterManager;
use App\Support\Connections\ConnectRequest;
use Illuminate\Validation\ValidationException;

/**
 * Connects a new store, enforcing the plan's `max_stores` limit (Plan §4.11:
 * connecting a second store is the paywall trigger for Free-tier teams).
 *
 * Guarded against creating duplicate `StoreConnection` rows for the same
 * store two ways: an `IdempotencyGuard` lock — keyed to team+platform+the
 * exact submitted credentials, not just team+platform, so connecting a
 * genuinely *different* Woo store moments later is never blocked, only a
 * true resubmission of the identical request — closes the true-concurrent
 * "double-tap on a slow connection" race, and — since WooCommerce
 * credentials carry a real, stable store identity (`store_url`) — an
 * existing-connection check by fingerprint, computed *before* creating,
 * catches a retry minutes/hours later too (past the lock's window), not
 * just a same-moment double-tap. Nothing here previously prevented this at
 * all: no unique DB constraint, no existing-row check.
 */
class ConnectStoreAction
{
    public function __construct(
        private readonly ChannelAdapterManager $adapters,
        private readonly ResolveEntitlementsAction $resolveEntitlements,
        private readonly ComputeStoreConnectionFingerprintAction $computeFingerprint,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function handle(Team $team, string $platform, string $name, array $credentials): StoreConnection
    {
        $maxStores = $this->resolveEntitlements->handle($team)['limits']['max_stores'] ?? null;

        if ($maxStores !== null) {
            $currentCount = StoreConnection::query()->where('team_id', $team->id)->count();

            if ($currentCount >= $maxStores) {
                PaywallHit::log($team, PlanLimit::MAX_STORES);

                throw ValidationException::withMessages([
                    'platform' => "You've reached your plan's store limit ({$maxStores}). Upgrade to connect more stores.",
                ]);
            }
        }

        $lockKey = 'connect:'.$team->id.':'.$platform.':'.md5(json_encode($credentials) ?: '');

        $connection = IdempotencyGuard::once($lockKey, 30, function () use ($team, $platform, $name, $credentials) {
            $fingerprint = $this->computeFingerprint->handle($platform, $credentials);

            if ($fingerprint !== null) {
                $existing = StoreConnection::query()
                    ->where('team_id', $team->id)
                    ->where('fingerprint', $fingerprint)
                    ->where('status', '!=', StoreConnection::STATUS_DISCONNECTED)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $connection = $this->adapters->driver($platform)->connect(
                new ConnectRequest($team, $name, $credentials),
            );

            $fingerprint ??= $this->computeFingerprint->handle($platform, $connection->credentials ?? []);

            if ($fingerprint !== null) {
                $connection->update(['fingerprint' => $fingerprint]);
            }

            return $connection;
        });

        if ($connection === null) {
            throw ValidationException::withMessages([
                'platform' => 'A connection attempt for this store is already in progress — please wait a moment and check your Connections list.',
            ]);
        }

        return $connection;
    }
}
