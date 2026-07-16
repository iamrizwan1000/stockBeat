<?php

namespace App\Actions\Connections;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Support\Connections\ChannelAdapterManager;
use App\Support\Connections\ConnectRequest;
use Illuminate\Validation\ValidationException;

/**
 * Connects a new store, enforcing the plan's `max_stores` limit (Plan §4.11:
 * connecting a second store is the paywall trigger for Free-tier teams).
 */
class ConnectStoreAction
{
    public function __construct(
        private readonly ChannelAdapterManager $adapters,
        private readonly ResolveEntitlementsAction $resolveEntitlements,
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
                throw ValidationException::withMessages([
                    'platform' => "You've reached your plan's store limit ({$maxStores}). Upgrade to connect more stores.",
                ]);
            }
        }

        return $this->adapters->driver($platform)->connect(
            new ConnectRequest($team, $name, $credentials),
        );
    }
}
