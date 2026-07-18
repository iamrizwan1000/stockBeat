<?php

namespace App\Console\Commands;

use App\Jobs\PollEbayFeedbackJob;
use App\Models\StoreConnection;
use Illuminate\Console\Command;

/**
 * Dispatches a feedback poll job for every active eBay connection to fetch
 * new negative buyer feedback (Plan §4.4's `negative_review` trigger,
 * §7.3) — mirrors `reviews:poll-woo`'s naming/shape for the same trigger.
 */
class PollEbayFeedback extends Command
{
    protected $signature = 'reviews:poll-ebay';

    protected $description = 'Poll every active eBay connection for negative buyer feedback';

    public function handle(): int
    {
        $connectionIds = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_EBAY)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            PollEbayFeedbackJob::dispatch($connectionId);
        }

        $this->info("Dispatched {$connectionIds->count()} eBay feedback poll job(s).");

        return self::SUCCESS;
    }
}
