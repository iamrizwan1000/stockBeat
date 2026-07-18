<?php

namespace App\Console\Commands;

use App\Jobs\PollAmazonOrdersJob;
use App\Models\StoreConnection;
use Illuminate\Console\Command;

/**
 * Dispatches a poll job for every active Amazon connection (Plan §7.5) —
 * the only sync path for Amazon in this v1 scope (no webhook ingestion of
 * the Notifications API/SQS is built).
 */
class PollAmazonConnections extends Command
{
    protected $signature = 'orders:poll-amazon';

    protected $description = 'Poll every active Amazon connection for new/updated orders';

    public function handle(): int
    {
        $connectionIds = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_AMAZON)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            PollAmazonOrdersJob::dispatch($connectionId);
        }

        $this->info("Dispatched {$connectionIds->count()} Amazon poll job(s).");

        return self::SUCCESS;
    }
}
