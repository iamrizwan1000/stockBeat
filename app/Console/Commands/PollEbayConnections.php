<?php

namespace App\Console\Commands;

use App\Jobs\PollEbayOrdersJob;
use App\Models\StoreConnection;
use Illuminate\Console\Command;

/**
 * Dispatches a poll job for every active eBay connection (Plan §7.3) — the
 * only sync path for eBay in this v1 scope, not just a webhook safety net.
 */
class PollEbayConnections extends Command
{
    protected $signature = 'orders:poll-ebay';

    protected $description = 'Poll every active eBay connection for new/updated orders';

    public function handle(): int
    {
        $connectionIds = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_EBAY)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            PollEbayOrdersJob::dispatch($connectionId);
        }

        $this->info("Dispatched {$connectionIds->count()} eBay poll job(s).");

        return self::SUCCESS;
    }
}
