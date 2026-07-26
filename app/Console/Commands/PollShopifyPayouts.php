<?php

namespace App\Console\Commands;

use App\Jobs\PollShopifyPayoutsJob;
use App\Models\StoreConnection;
use Illuminate\Console\Command;

/**
 * Dispatches a payout poll job for every active Shopify connection (Plan
 * §4.14) — mirrors `products:poll-ebay`'s naming/shape for the same
 * per-connection dispatch pattern.
 */
class PollShopifyPayouts extends Command
{
    protected $signature = 'payouts:poll-shopify';

    protected $description = 'Poll every active Shopify connection for payout events';

    public function handle(): int
    {
        $connectionIds = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_SHOPIFY)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            PollShopifyPayoutsJob::dispatch($connectionId);
        }

        $this->info("Dispatched {$connectionIds->count()} Shopify payout poll job(s).");

        return self::SUCCESS;
    }
}
