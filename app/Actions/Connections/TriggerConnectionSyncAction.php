<?php

namespace App\Actions\Connections;

use App\Jobs\PollAmazonOrdersJob;
use App\Jobs\PollEbayOrdersJob;
use App\Jobs\PollEtsyOrdersJob;
use App\Jobs\PollShopifyOrdersJob;
use App\Jobs\PollTikTokOrdersJob;
use App\Jobs\PollWooOrdersJob;
use App\Models\StoreConnection;
use App\Support\Concurrency\IdempotencyGuard;
use Illuminate\Support\Facades\Cache;

/**
 * Manual "sync now" trigger (Connections list screen) — dispatches the same
 * platform-specific order-poll job the automatic scheduler and the
 * immediate connect-time dispatch already use, just on demand.
 *
 * Rate-limited **per connection**, not per team/user — every platform's API
 * has its own per-store rate limit a merchant repeatedly tapping "sync now"
 * could otherwise burn through, and a team with multiple stores shouldn't
 * be blocked from syncing store B just because they synced store A a
 * moment ago. This is deliberately not a "refresh everything" action.
 *
 * The cooldown itself is enforced by `IdempotencyGuard`'s atomic
 * `Cache::lock()` — an earlier version used a plain `Cache::get()`-then-
 * `Cache::put()` check, which is a genuine race under two truly
 * simultaneous requests (both can read "no cooldown" before either writes
 * one, dispatching twice). The separate `-until` cache key below is
 * display-only (the human-readable "retry in N seconds" message), written
 * only by whichever request actually wins the lock — it never gates the
 * dispatch itself.
 */
class TriggerConnectionSyncAction
{
    private const COOLDOWN_SECONDS = 60;

    /**
     * @return array{dispatched: bool, retry_after_seconds: int}
     */
    public function handle(StoreConnection $connection): array
    {
        $jobClass = $this->jobClassFor($connection->platform);

        if ($jobClass === null) {
            return ['dispatched' => false, 'retry_after_seconds' => 0];
        }

        $dispatched = IdempotencyGuard::once($this->lockKey($connection), self::COOLDOWN_SECONDS, function () use ($connection, $jobClass) {
            $jobClass::dispatch($connection->id);
            Cache::put($this->untilKey($connection), now()->addSeconds(self::COOLDOWN_SECONDS), self::COOLDOWN_SECONDS);

            return true;
        });

        if ($dispatched === null) {
            $cooldownUntil = Cache::get($this->untilKey($connection));
            $retryAfterSeconds = $cooldownUntil !== null ? max(now()->diffInSeconds($cooldownUntil), 1) : self::COOLDOWN_SECONDS;

            return ['dispatched' => false, 'retry_after_seconds' => $retryAfterSeconds];
        }

        return ['dispatched' => true, 'retry_after_seconds' => 0];
    }

    private function lockKey(StoreConnection $connection): string
    {
        return "connection-sync-now:{$connection->id}";
    }

    private function untilKey(StoreConnection $connection): string
    {
        return "connection-sync-now:{$connection->id}:until";
    }

    /**
     * @return class-string|null
     */
    private function jobClassFor(string $platform): ?string
    {
        return match ($platform) {
            StoreConnection::PLATFORM_WOO => PollWooOrdersJob::class,
            StoreConnection::PLATFORM_SHOPIFY => PollShopifyOrdersJob::class,
            StoreConnection::PLATFORM_EBAY => PollEbayOrdersJob::class,
            StoreConnection::PLATFORM_ETSY => PollEtsyOrdersJob::class,
            StoreConnection::PLATFORM_AMAZON => PollAmazonOrdersJob::class,
            StoreConnection::PLATFORM_TIKTOK => PollTikTokOrdersJob::class,
            default => null,
        };
    }
}
