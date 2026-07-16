<?php

namespace App\Console\Commands;

use App\Jobs\PollWooReviewsJob;
use App\Models\StoreConnection;
use Illuminate\Console\Command;

/**
 * Dispatches a review poll job for every active WooCommerce connection
 * (Plan §4.4 negative_review trigger).
 */
class PollWooReviews extends Command
{
    protected $signature = 'reviews:poll-woo';

    protected $description = 'Poll every active WooCommerce connection for new product reviews';

    public function handle(): int
    {
        $connectionIds = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_WOO)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            PollWooReviewsJob::dispatch($connectionId);
        }

        $this->info("Dispatched {$connectionIds->count()} Woo review poll job(s).");

        return self::SUCCESS;
    }
}
