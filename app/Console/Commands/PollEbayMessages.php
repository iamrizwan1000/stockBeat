<?php

namespace App\Console\Commands;

use App\Jobs\PollEbayMessagesJob;
use App\Models\StoreConnection;
use Illuminate\Console\Command;

/**
 * Dispatches a poll job for every active eBay connection to fetch new
 * Trading API buyer member messages into the unified inbox (Plan §4.5) —
 * the inbound half of eBay's flagship messaging channel, mirroring
 * `orders:poll-ebay`'s "no webhooks, polling is the whole mechanism"
 * posture.
 */
class PollEbayMessages extends Command
{
    protected $signature = 'inbox:poll-ebay-messages';

    protected $description = 'Poll every active eBay connection for new buyer member messages';

    public function handle(): int
    {
        $connectionIds = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_EBAY)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            PollEbayMessagesJob::dispatch($connectionId);
        }

        $this->info("Dispatched {$connectionIds->count()} eBay message poll job(s).");

        return self::SUCCESS;
    }
}
