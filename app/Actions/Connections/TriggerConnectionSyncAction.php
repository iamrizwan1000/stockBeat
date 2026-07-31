<?php

namespace App\Actions\Connections;

use App\Jobs\PollAmazonOrdersJob;
use App\Jobs\PollEbayOrdersJob;
use App\Jobs\PollEtsyOrdersJob;
use App\Jobs\PollShopifyOrdersJob;
use App\Jobs\PollShopifyProductsJob;
use App\Jobs\PollTikTokOrdersJob;
use App\Jobs\PollWooOrdersJob;
use App\Jobs\PollWooProductsJob;
use App\Models\StoreConnection;
use App\Support\Concurrency\IdempotencyGuard;
use Illuminate\Support\Facades\Cache;

/**
 * Manual "sync now" trigger (Connections list screen) — dispatches the same
 * platform-specific order-poll job the automatic scheduler and the
 * immediate connect-time dispatch already use, just on demand. Also
 * dispatches the platform's product-catalog poll job when one exists (Woo,
 * Shopify) — added 2026-07-31 so "sync now" actually refreshes what a
 * merchant tapping it would expect (new products, SKU/stock changes), not
 * just orders.
 *
 * Rate-limited **per connection**, not per team/user — every platform's API
 * has its own per-store rate limit a merchant repeatedly tapping "sync now"
 * could otherwise burn through, and a team with multiple stores shouldn't
 * be blocked from syncing store B just because they synced store A a
 * moment ago. Still deliberately not "refresh literally everything" —
 * reviews and payouts stay on their own scheduled cadence, since those
 * aren't what a merchant means by "sync now" on the Connections screen.
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

        $productsJobClass = $this->productsJobClassFor($connection->platform);

        $dispatched = IdempotencyGuard::once($this->lockKey($connection), self::COOLDOWN_SECONDS, function () use ($connection, $jobClass, $productsJobClass) {
            $jobClass::dispatch($connection->id);

            if ($productsJobClass !== null) {
                $productsJobClass::dispatch($connection->id);
            }

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

    /**
     * @return class-string|null
     */
    private function productsJobClassFor(string $platform): ?string
    {
        return match ($platform) {
            StoreConnection::PLATFORM_WOO => PollWooProductsJob::class,
            StoreConnection::PLATFORM_SHOPIFY => PollShopifyProductsJob::class,
            // eBay/Etsy/Amazon/TikTok have no product-catalog poll job at
            // all yet — nothing to dispatch, not an oversight here.
            default => null,
        };
    }
}
