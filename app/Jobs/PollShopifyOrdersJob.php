<?php

namespace App\Jobs;

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\Shopify\ShopifyOrderMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

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

    public function handle(ShopifyOrderMapper $mapper, IngestOrderAction $ingestOrder): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->platform !== StoreConnection::PLATFORM_SHOPIFY) {
            return;
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
