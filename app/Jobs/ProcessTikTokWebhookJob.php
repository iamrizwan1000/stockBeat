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
 * The actual DB-mutating side of a verified TikTok Shop webhook, moved off
 * the request/response cycle onto the `ingest` queue (Plan §15.1) — same
 * shape as `ProcessWooWebhookJob`/`ProcessShopifyWebhookJob`. Unlike those
 * two, `TikTokAdapter::parseWebhook()`'s payload is a thin order-status-
 * change notification (just an order id), not the full order object — so
 * this job re-fetches the full order via `TikTokAdapter::fetchOrderDetail()`
 * before ingesting, rather than mapping the webhook body directly.
 */
class ProcessTikTokWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    /**
     * @param  array<string, mixed>  $parsed
     */
    public function __construct(
        public readonly int $connectionId,
        public readonly array $parsed,
    ) {}

    public function handle(IngestOrderAction $ingestOrder, TikTokAdapter $adapter): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->platform !== StoreConnection::PLATFORM_TIKTOK) {
            return;
        }

        // Paused by a downgrade freeze (Plan §6.4) — same guard
        // `ProcessWooWebhookJob` applies, since webhooks arrive independently
        // of polling and the reconciliation poller already skips paused
        // connections.
        if ($connection->status === StoreConnection::STATUS_PAUSED) {
            return;
        }

        $externalId = $this->parsed['external_id'] ?? null;

        if ($externalId === null) {
            return;
        }

        foreach ($adapter->fetchOrderDetail($connection, (string) $externalId) as $normalized) {
            $ingestOrder->handle($connection, $normalized);
        }
    }
}
