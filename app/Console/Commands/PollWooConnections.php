<?php

namespace App\Console\Commands;

use App\Jobs\PollWooOrdersJob;
use App\Models\StoreConnection;
use Illuminate\Console\Command;

/**
 * Dispatches a reconciliation poll job for every active WooCommerce
 * connection (Plan §7.2 webhook safety net, §8.2 scheduler).
 */
class PollWooConnections extends Command
{
    protected $signature = 'orders:poll-woo';

    protected $description = 'Poll every active WooCommerce connection for orders webhooks may have missed';

    public function handle(): int
    {
        $connectionIds = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_WOO)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            PollWooOrdersJob::dispatch($connectionId);
        }

        $this->info("Dispatched {$connectionIds->count()} Woo poll job(s).");

        return self::SUCCESS;
    }
}
