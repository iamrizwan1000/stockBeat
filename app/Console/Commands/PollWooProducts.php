<?php

namespace App\Console\Commands;

use App\Jobs\PollWooProductsJob;
use App\Models\StoreConnection;
use Illuminate\Console\Command;

/**
 * Dispatches a product/stock poll job for every active WooCommerce
 * connection (Plan §4.4 low_stock trigger).
 */
class PollWooProducts extends Command
{
    protected $signature = 'products:poll-woo';

    protected $description = 'Poll every active WooCommerce connection for product stock levels';

    public function handle(): int
    {
        $connectionIds = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_WOO)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            PollWooProductsJob::dispatch($connectionId);
        }

        $this->info("Dispatched {$connectionIds->count()} Woo product poll job(s).");

        return self::SUCCESS;
    }
}
