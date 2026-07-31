<?php

namespace App\Console\Commands;

use App\Jobs\PollShopifyProductsJob;
use App\Models\StoreConnection;
use Illuminate\Console\Command;

/**
 * Dispatches a product/stock poll job for every active Shopify connection
 * (Plan §4.4 low_stock/back_in_stock/stale-inventory triggers), same role
 * as `PollWooProducts`.
 */
class PollShopifyProducts extends Command
{
    protected $signature = 'products:poll-shopify';

    protected $description = 'Poll every active Shopify connection for product stock levels';

    public function handle(): int
    {
        $connectionIds = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_SHOPIFY)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            PollShopifyProductsJob::dispatch($connectionId);
        }

        $this->info("Dispatched {$connectionIds->count()} Shopify product poll job(s).");

        return self::SUCCESS;
    }
}
