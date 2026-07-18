<?php

namespace App\Jobs;

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\Etsy\EtsyOrderMapper;
use App\Support\Connections\Adapters\EtsyAdapter;
use App\Support\Connections\ApiQuotaTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Etsy's only order-sync path (Plan §7.4: "No webhooks — polling only").
 * Same proactive-token-refresh pattern as `PollEbayOrdersJob` — Etsy
 * access tokens are also short-lived (~1hr default).
 */
class PollEtsyOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('poll');
    }

    public function handle(EtsyOrderMapper $mapper, IngestOrderAction $ingestOrder, EtsyAdapter $adapter): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->platform !== StoreConnection::PLATFORM_ETSY) {
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
        $shopId = $credentials['shop_id'] ?? null;
        $minLastModified = ($connection->last_sync_at ?? now()->subDay())->timestamp;

        $response = Http::withHeaders(['x-api-key' => config('services.etsy.keystring')])
            ->withToken($token)
            ->acceptJson()
            ->get("https://api.etsy.com/v3/application/shops/{$shopId}/receipts", [
                'min_last_modified' => $minLastModified,
                'limit' => 100,
            ]);

        // One real outbound call against Etsy's 10k requests/day budget
        // (Plan §7.4) — see `ApiQuotaTracker`'s own docblock for why this
        // hook lives here rather than on the adapter.
        ApiQuotaTracker::recordCall(StoreConnection::PLATFORM_ETSY);

        if ($response->status() === 401) {
            $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

            return;
        }

        if ($response->failed()) {
            // Transient failure — the next scheduled run retries.
            return;
        }

        /** @var array<int, array<string, mixed>> $receipts */
        $receipts = (array) $response->json('results', []);

        foreach ($receipts as $rawReceipt) {
            $ingestOrder->handle($connection, $mapper->map($rawReceipt));
        }

        $connection->update([
            'last_sync_at' => now(),
            'status' => StoreConnection::STATUS_ACTIVE,
        ]);
    }
}
