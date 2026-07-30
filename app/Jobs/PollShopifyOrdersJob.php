<?php

namespace App\Jobs;

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\Shopify\ShopifyOrderMapper;
use App\Support\Connections\Adapters\ShopifyAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reconciliation poller (Plan §7.1 gotcha: "webhook deliveries can drop —
 * run reconciliation polling every 10-15 min as safety net"), same role as
 * `PollWooOrdersJob`.
 */
class PollShopifyOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    private const API_VERSION = '2026-07';

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('poll');
    }

    public function handle(ShopifyOrderMapper $mapper, IngestOrderAction $ingestOrder, ShopifyAdapter $adapter): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->platform !== StoreConnection::PLATFORM_SHOPIFY) {
            return;
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $expiresAt = isset($credentials['expires_at']) ? Carbon::parse($credentials['expires_at']) : null;

        // Silently refreshes via the refresh token rather than jumping
        // straight to needs_reauth — same pattern as eBay/Etsy. A
        // connection with no refresh_token at all (made before expiring
        // tokens shipped) has nothing to refresh with, so refreshAuth()
        // itself routes that case to needs_reauth.
        if ($expiresAt === null || $expiresAt->isPast()) {
            $adapter->refreshAuth($connection);
            $connection = $connection->fresh();

            if ($connection === null || $connection->status === StoreConnection::STATUS_NEEDS_REAUTH) {
                return;
            }
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $shop = (string) ($credentials['shop_domain'] ?? '');
        $token = (string) ($credentials['access_token'] ?? '');
        $updatedAtMin = ($connection->last_sync_at ?? now()->subDay())->toIso8601String();

        $response = Http::baseUrl("https://{$shop}/admin/api/".self::API_VERSION)
            ->withHeaders(['X-Shopify-Access-Token' => $token])
            ->acceptJson()
            ->get('/orders.json', [
                'status' => 'any',
                'updated_at_min' => $updatedAtMin,
                'limit' => 100,
            ]);

        // 403 here (distinct from scope/permission 403s elsewhere) covers
        // Shopify's non-expiring-token deprecation: a connection made
        // before this job tracked `expires_at` (or before Shopify enforced
        // this at all) holds a token the Admin API now refuses outright,
        // with no expiry to have caught proactively above. Matched on the
        // actual error text rather than treating every 403 as a reauth
        // signal, since other 403 causes (e.g. a scope gap) aren't fixed by
        // reconnecting the same way.
        $isDeadNonExpiringToken = $response->status() === 403
            && str_contains(strtolower($response->body()), 'non-expiring access token');

        if ($response->status() === 401 || $isDeadNonExpiringToken) {
            $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

            return;
        }

        if ($response->failed()) {
            // Transient failure — the next scheduled run retries. Logged
            // because this used to fail completely silently: the queue
            // sees a "successful" job (it returned, didn't throw), so
            // nothing showed in Horizon's failed-jobs list no matter how
            // many times this kept not-syncing.
            Log::warning('Shopify order poll failed', [
                'connection_id' => $connection->id,
                'shop' => $shop,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return;
        }

        /** @var array<int, array<string, mixed>> $orders */
        $orders = (array) $response->json('orders', []);

        foreach ($orders as $rawOrder) {
            $ingestOrder->handle($connection, $mapper->map($rawOrder));
        }

        $connection->update([
            'last_sync_at' => now(),
            'status' => StoreConnection::STATUS_ACTIVE,
        ]);
    }
}
