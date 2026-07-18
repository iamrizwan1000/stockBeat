<?php

namespace App\Support\Connections;

use Illuminate\Support\Facades\Cache;

/**
 * Minimal per-platform-per-day outbound API call counter (Plan §8.7.7 gap
 * #1 — "API quota usage for platforms omitted"). One cache key per
 * platform per calendar day (`api_quota:{platform}:{Y-m-d}`), incremented
 * at the lowest-level call site inside each adapter/poll job that actually
 * issues the real outbound HTTP request:
 *
 *  - `PollEtsyOrdersJob` — the inline `GET .../receipts` call (Etsy's poll
 *    job builds its own request rather than delegating to the adapter).
 *  - `PollEbayOrdersJob` — the inline `GET .../fulfillment/v1/order` call.
 *  - `EbayAdapter::fetchNegativeFeedback()`/`fetchInventoryItems()` — the
 *    Trading API `GetFeedback` call and each paginated Inventory API page.
 *  - `AmazonAdapter::signedRequest()` — the single shared low-level SigV4
 *    request method every Amazon data-plane call funnels through
 *    (`fetchOrders()`'s getOrders pages + its per-order `fetchOrderItems()`
 *    calls), so hooking it once here covers all of them.
 *  - `TikTokAdapter::signedRequest()` — same shared-funnel shape as
 *    Amazon's, covers `fetchOrders()`/`fetchOrderDetail()`.
 *
 * Deliberately cache-based rather than a new DB table: nothing else in
 * this codebase persists outbound call counts anywhere (checked before
 * building this — the only existing "count events, reset on a timer"
 * primitive is `RateLimiter`'s own cache-backed counters, e.g. the
 * `otp-request`/`otp-verify` limiters in `AppServiceProvider`), the count
 * only needs to survive a day, and a cache increment is atomic on every
 * store this app configures (`database`, `array`, `redis`) without
 * needing a migration or model for a number that resets daily anyway.
 */
class ApiQuotaTracker
{
    /**
     * Survives a day plus slack — a stale key lingering a little past
     * midnight (e.g. a request that lands right at the day boundary) is
     * harmless since it's keyed by date already; this just avoids the key
     * disappearing mid-read on a slow cache store.
     */
    private const TTL_SECONDS = 172800;

    public static function recordCall(string $platform, int $calls = 1): void
    {
        $key = self::cacheKey($platform);

        // Cache::add() only ever takes effect the first time a given
        // day's key is created, so this sets the expiry once without
        // resetting it (or the running count) on every subsequent call.
        Cache::add($key, 0, self::TTL_SECONDS);
        Cache::increment($key, $calls);
    }

    public static function callsToday(string $platform): int
    {
        return (int) Cache::get(self::cacheKey($platform), 0);
    }

    private static function cacheKey(string $platform): string
    {
        return sprintf('api_quota:%s:%s', $platform, now()->toDateString());
    }
}
