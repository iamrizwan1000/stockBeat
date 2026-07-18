<?php

namespace App\Console\Commands;

use App\Jobs\PollEbayInventoryJob;
use App\Models\StoreConnection;
use Illuminate\Console\Command;

/**
 * Dispatches a product/stock poll job for every active eBay connection
 * (Plan §4.4's `low_stock` trigger) — mirrors `products:poll-woo`'s naming/
 * shape for the same trigger.
 */
class PollEbayInventory extends Command
{
    protected $signature = 'products:poll-ebay';

    protected $description = 'Poll every active eBay connection for product stock levels';

    public function handle(): int
    {
        $connectionIds = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_EBAY)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            PollEbayInventoryJob::dispatch($connectionId);
        }

        $this->info("Dispatched {$connectionIds->count()} eBay inventory poll job(s).");

        return self::SUCCESS;
    }
}
