<?php

namespace App\Jobs;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Actions\Orders\IngestOrderAction;
use App\Jobs\Concerns\PaginatesShopifyLinkHeader;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\Shopify\ShopifyOrderMapper;
use App\Support\Connections\Adapters\ShopifyAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reconciliation poller (Plan §7.1 gotcha: "webhook deliveries can drop —
 * run reconciliation polling every 10-15 min as safety net"), same role as
 * `PollWooOrdersJob`. Also the connection's *first* sync, dispatched
 * immediately on connect — that dual role is why `last_sync_at === null`
 * gets a much wider backfill window below rather than the normal narrow
 * reconciliation one.
 */
class PollShopifyOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PaginatesShopifyLinkHeader, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    private const API_VERSION = '2026-07';

    /**
     * Applies only to a connection's first-ever sync (`last_sync_at` is
     * still null) — ongoing reconciliation always uses the real
     * `last_sync_at` instead, regardless of this. A team's own
     * `history_days` plan limit takes precedence when set (no point
     * backfilling further back than the plan will ever display); this is
     * only the fallback for an unlimited plan, so a first sync on a very
     * old store doesn't try to pull years of orders in one job run.
     */
    private const DEFAULT_BACKFILL_DAYS = 90;

    /**
     * Safety cap on a single run's pagination — 20 pages × 100/page =
     * up to 2,000 orders backfilled per run. A store with more matching
     * orders than that in one sync won't get a fully complete historical
     * backfill in a single run (logged when this happens, not silent);
     * newly-arriving orders are unaffected either way since those come
     * through in real time via webhook regardless of this cap.
     */
    private const MAX_PAGES = 20;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('poll');
    }

    public function handle(ShopifyOrderMapper $mapper, IngestOrderAction $ingestOrder, ShopifyAdapter $adapter, ResolveEntitlementsAction $resolveEntitlements): void
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
        $isFirstSync = $connection->last_sync_at === null;
        $updatedAtMin = $isFirstSync
            ? now()->subDays($this->backfillDays($connection, $resolveEntitlements))->toIso8601String()
            : $connection->last_sync_at->toIso8601String();

        $client = Http::baseUrl("https://{$shop}/admin/api/".self::API_VERSION)
            ->withHeaders(['X-Shopify-Access-Token' => $token])
            ->acceptJson();

        $path = '/orders.json';
        $query = ['status' => 'any', 'updated_at_min' => $updatedAtMin, 'limit' => 100];
        $page = 0;

        do {
            $response = $query === null ? $client->get($path) : $client->get($path, $query);
            $page++;

            if ($page === 1 && ! $this->handleFirstPageFailure($response, $connection, $shop)) {
                return;
            }

            if ($page > 1 && $response->failed()) {
                // A later page failing mid-backfill isn't treated as a
                // full failure — whatever was already ingested this run
                // stays, last_sync_at still advances below. The next
                // scheduled reconciliation run picks up from there.
                Log::warning('Shopify order poll: pagination stopped early', [
                    'connection_id' => $connection->id,
                    'page' => $page,
                    'status' => $response->status(),
                ]);

                break;
            }

            /** @var array<int, array<string, mixed>> $orders */
            $orders = (array) $response->json('orders', []);

            foreach ($orders as $rawOrder) {
                $ingestOrder->handle($connection, $mapper->map($rawOrder));
            }

            $path = $this->nextPageUrl($response);
            $query = null;
        } while ($path !== null && $page < self::MAX_PAGES);

        if ($path !== null) {
            Log::warning('Shopify order poll: hit the per-run page cap with more pages remaining', [
                'connection_id' => $connection->id,
                'pages_fetched' => $page,
            ]);
        }

        $connection->update([
            'last_sync_at' => now(),
            'status' => StoreConnection::STATUS_ACTIVE,
        ]);
    }

    /**
     * Handles the auth-related outcomes only the *first* page's response
     * can meaningfully signal (a later page repeating the same failure
     * would just be noise). Returns false when the caller should stop
     * (connection already updated to reflect the failure), true to
     * continue processing.
     */
    private function handleFirstPageFailure(Response $response, StoreConnection $connection, string $shop): bool
    {
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

            return false;
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

            return false;
        }

        return true;
    }

    private function backfillDays(StoreConnection $connection, ResolveEntitlementsAction $resolveEntitlements): int
    {
        $team = $connection->team;

        if ($team === null) {
            return self::DEFAULT_BACKFILL_DAYS;
        }

        $historyDays = $resolveEntitlements->handle($team)['limits']['history_days'] ?? null;

        return is_numeric($historyDays) ? (int) $historyDays : self::DEFAULT_BACKFILL_DAYS;
    }
}
