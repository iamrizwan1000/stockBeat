<?php

namespace App\Jobs;

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\TikTokAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reconciliation poller (Plan §7.2/§17.2 style safety net) for TikTok Shop —
 * unlike Amazon/eBay/Etsy (where polling is the *only* sync path), TikTok
 * Shop has real webhooks as its primary sync (`TikTokAdapter::
 * registerWebhooks()`/`parseWebhook()`), so this only catches whatever a
 * dropped/missed webhook delivery would otherwise leave stale — same
 * framing as `PollWooOrdersJob`.
 */
class PollTikTokOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('poll');
    }

    public function handle(IngestOrderAction $ingestOrder, TikTokAdapter $adapter): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->platform !== StoreConnection::PLATFORM_TIKTOK) {
            return;
        }

        $since = $connection->last_sync_at ?? now()->subDay();

        $orders = $adapter->fetchOrders($connection, $since);

        // fetchOrders() itself flips the connection to needs_reauth on a
        // 401/403 (mirroring PollAmazonOrdersJob) — don't stomp that back to
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
