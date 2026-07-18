<?php

namespace App\Jobs;

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\AmazonAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Amazon's only order-sync path (Plan §7.5) — real-time delivery is the
 * Notifications API pushing into Amazon's own SQS/EventBridge, not
 * something this Laravel app can receive as an inbound webhook, so
 * `AmazonAdapter::registerWebhooks()` is a deliberate no-op and this
 * reconciliation poller is the whole sync mechanism, same "polling is a
 * fully correct v1 strategy" reasoning already used for eBay/Etsy.
 *
 * Unlike `PollEtsyOrdersJob`/`PollEbayOrdersJob`, the actual HTTP work
 * (SigV4 signing, RDT issuance, two-level NextToken pagination across
 * getOrders/getOrderItems) lives on `AmazonAdapter::fetchOrders()` rather
 * than inline here — see that method's own docblock for why.
 */
class PollAmazonOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('poll');
    }

    public function handle(IngestOrderAction $ingestOrder, AmazonAdapter $adapter): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->platform !== StoreConnection::PLATFORM_AMAZON) {
            return;
        }

        $since = $connection->last_sync_at ?? now()->subDay();

        $orders = $adapter->fetchOrders($connection, $since);

        // fetchOrders() itself flips the connection to needs_reauth on a
        // 401/403 (mirroring the other pollers) — don't stomp that back to
        // active below.
        if ($connection->status === StoreConnection::STATUS_NEEDS_REAUTH) {
            return;
        }

        foreach ($orders as $normalized) {
            $ingestOrder->handle($connection, $normalized);
        }

        $connection->update([
            'last_sync_at' => now(),
            'status' => StoreConnection::STATUS_ACTIVE,
        ]);
    }
}
