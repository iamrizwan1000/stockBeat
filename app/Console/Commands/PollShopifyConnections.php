<?php

namespace App\Console\Commands;

use App\Jobs\PollShopifyOrdersJob;
use App\Models\StoreConnection;
use Illuminate\Console\Command;

/**
 * Dispatches a reconciliation poll job for every active Shopify connection
 * (Plan §7.1 webhook safety net), same role as `PollWooConnections`.
 */
class PollShopifyConnections extends Command
{
    protected $signature = 'orders:poll-shopify';

    protected $description = 'Poll every active Shopify connection for orders webhooks may have missed';

    public function handle(): int
    {
        $connectionIds = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_SHOPIFY)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            PollShopifyOrdersJob::dispatch($connectionId);
        }

        $this->info("Dispatched {$connectionIds->count()} Shopify poll job(s).");

        return self::SUCCESS;
    }
}
