<?php

namespace App\Jobs;

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\Ebay\EbayOrderMapper;
use App\Support\Connections\Adapters\EbayAdapter;
use App\Support\Connections\ApiQuotaTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * eBay's only order-sync path (Plan §7.3) — `EbayAdapter::registerWebhooks()`
 * is a deliberate no-op for v1, so this reconciliation poller is not a
 * safety net here, it's the whole sync mechanism. Also carries eBay's
 * short-lived (~2hr) access token refresh — proactively refreshed here
 * rather than waiting for a 401, since this runs every 15 minutes and the
 * token would otherwise expire mid-cycle (Plan §7.3 gotcha: "token refresh
 * must be rock-solid").
 */
class PollEbayOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('poll');
    }

    public function handle(EbayOrderMapper $mapper, IngestOrderAction $ingestOrder, EbayAdapter $adapter): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->platform !== StoreConnection::PLATFORM_EBAY) {
            return;
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $expiresAt = isset($credentials['expires_at']) ? Carbon::parse($credentials['expires_at']) : null;

        if ($expiresAt === null || $expiresAt->isPast()) {
            $adapter->refreshAuth($connection);
            $connection = $connection->fresh();

            if ($connection === null || $connection->status === StoreConnection::STATUS_NEEDS_REAUTH) {
                return;
            }
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $token = (string) ($credentials['access_token'] ?? '');
        $baseUrl = config('services.ebay.env', 'sandbox') === 'sandbox' ? 'https://api.sandbox.ebay.com' : 'https://api.ebay.com';
        $since = ($connection->last_sync_at ?? now()->subDay())->toIso8601String();

        $response = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->get('/sell/fulfillment/v1/order', [
                'filter' => "lastmodifieddate:[{$since}..]",
                'limit' => 50,
            ]);

        // One real outbound call against eBay's ~5k/day/API budget (Plan
        // §7.3) — see `ApiQuotaTracker`'s own docblock for why this hook
        // lives here rather than on the adapter.
        ApiQuotaTracker::recordCall(StoreConnection::PLATFORM_EBAY);

        if ($response->status() === 401) {
            $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

            return;
        }

        if ($response->failed()) {
            // Transient failure — the next scheduled run retries.
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
