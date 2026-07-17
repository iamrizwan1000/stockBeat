<?php

namespace App\Console\Commands;

use App\Jobs\PollEtsyOrdersJob;
use App\Models\StoreConnection;
use Illuminate\Console\Command;

/**
 * Dispatches a poll job for every active Etsy connection (Plan §7.4) — the
 * only sync path for Etsy, which has no webhooks at all.
 */
class PollEtsyConnections extends Command
{
    protected $signature = 'orders:poll-etsy';

    protected $description = 'Poll every active Etsy connection for new/updated receipts';

    public function handle(): int
    {
        $connectionIds = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_ETSY)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            PollEtsyOrdersJob::dispatch($connectionId);
        }

        $this->info("Dispatched {$connectionIds->count()} Etsy poll job(s).");

        return self::SUCCESS;
    }
}
