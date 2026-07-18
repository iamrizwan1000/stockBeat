<?php

namespace App\Console\Commands;

use App\Jobs\PollTikTokOrdersJob;
use App\Models\StoreConnection;
use Illuminate\Console\Command;

/**
 * Dispatches a poll job for every active TikTok Shop connection (Plan
 * §7.6) — a reconciliation safety net here, not the primary sync path
 * (TikTok Shop's real webhooks carry that load — see `TikTokAdapter`'s own
 * docblock), same relationship `orders:poll-woo` has to Woo's webhooks.
 */
class PollTikTokConnections extends Command
{
    protected $signature = 'orders:poll-tiktok';

    protected $description = 'Poll every active TikTok Shop connection for new/updated orders (webhook reconciliation safety net)';

    public function handle(): int
    {
        $connectionIds = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_TIKTOK)
            ->where('status', StoreConnection::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($connectionIds as $connectionId) {
            PollTikTokOrdersJob::dispatch($connectionId);
        }

        $this->info("Dispatched {$connectionIds->count()} TikTok Shop poll job(s).");

        return self::SUCCESS;
    }
}
