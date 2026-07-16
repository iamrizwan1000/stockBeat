<?php

namespace App\Jobs;

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\Woo\WooOrderMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Reconciliation poller (Plan §7.2, §17.2/§17.3): webhooks can drop, so this
 * is the safety net that runs every 10-15 minutes per active Woo connection,
 * fetching anything modified since the last successful sync.
 */
class PollWooOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('poll');
    }

    public function handle(WooOrderMapper $mapper, IngestOrderAction $ingestOrder): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->platform !== StoreConnection::PLATFORM_WOO) {
            return;
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $modifiedAfter = ($connection->last_sync_at ?? now()->subDay())->toIso8601String();

        $response = Http::withBasicAuth((string) $credentials['consumer_key'], (string) $credentials['consumer_secret'])
            ->get($credentials['store_url'].'/wp-json/wc/v3/orders', [
                'modified_after' => $modifiedAfter,
                'per_page' => 100,
                'orderby' => 'modified',
                'order' => 'asc',
            ]);

        if ($response->status() === 401) {
            $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

            return;
        }

        if ($response->failed()) {
            // Transient failure — the next scheduled run retries (§17.2:
            // adaptive retry with backoff, never a silent permanent gap).
            return;
        }

        /** @var array<int, array<string, mixed>> $orders */
        $orders = (array) $response->json();

        foreach ($orders as $rawOrder) {
            $ingestOrder->handle($connection, $mapper->map($rawOrder));
        }

        $connection->update([
            'last_sync_at' => now(),
            'status' => StoreConnection::STATUS_ACTIVE,
        ]);
    }
}
