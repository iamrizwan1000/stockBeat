<?php

namespace App\Jobs;

use App\Actions\Inbox\IngestEbayMemberMessageAction;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\EbayAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Inbound half of eBay's flagship messaging channel (Plan §4.5/§7.3):
 * `EbayAdapter::sendMessage()` covers outbound replies, this polls the
 * Trading API for new buyer member messages and lands them in the unified
 * inbox via `IngestEbayMemberMessageAction`. Same "no webhooks, polling is
 * the whole sync mechanism" posture as `PollEbayOrdersJob` (also carries the
 * same proactive access-token refresh, since eBay's ~2hr tokens would
 * otherwise expire mid-cycle) — deliberately a separate job/cursor
 * (`last_message_sync_at`) rather than piggybacking on the order poller's
 * `last_sync_at`, so the two schedules stay independent.
 */
class PollEbayMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('poll');
    }

    public function handle(EbayAdapter $adapter, IngestEbayMemberMessageAction $ingest): void
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

        $since = $connection->last_message_sync_at ?? now()->subDay();

        foreach ($adapter->fetchMemberMessages($connection, $since) as $raw) {
            $ingest->handle($connection, $raw);
        }

        $connection->update(['last_message_sync_at' => now()]);
    }
}
