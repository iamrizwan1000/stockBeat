<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reconciliation poller — webhook safety net (Plan §7.2 gotcha: "run every
// 10-15 min").
Schedule::command('orders:poll-woo')->everyFifteenMinutes();
Schedule::command('orders:poll-shopify')->everyFifteenMinutes();

// eBay has no webhook subscription built (v1 scope cut, EbayAdapter) — this
// is the only sync path, not just a safety net, so it runs more frequently
// than the pure-reconciliation pollers above.
Schedule::command('orders:poll-ebay')->everyFiveMinutes();

// Etsy has no webhooks at all (Plan §7.4) — same reasoning as eBay above.
// 5 min stays well within Etsy's 10k requests/day budget per app (§7.4).
Schedule::command('orders:poll-etsy')->everyFiveMinutes();

// Amazon has no webhook ingress in this v1 scope either (Notifications API
// → SQS/EventBridge is future work, Plan §7.5) — this is the only sync
// path. Slower than eBay/Etsy's 5-minute cadence on purpose: Amazon's
// getOrders rate limit is far stricter (~0.0167 rps, burst 20) than eBay/
// Etsy's ~5,000-10,000/day budgets, and each poll fans out into several
// calls per connection (RDT + getOrders pages + one getOrderItems call per
// order), so 15 minutes keeps sustained usage comfortably inside that
// budget without a dedicated token-bucket-aware queue (Plan §15.1's Redis/
// Horizon item — not built yet, same scope cut noted elsewhere).
Schedule::command('orders:poll-amazon')->everyFifteenMinutes();

// TikTok Shop has real webhooks as its primary sync path (Plan §7.6,
// TikTokAdapter::registerWebhooks()/parseWebhook()) — unlike every poller
// above, this is only the reconciliation safety net for dropped/missed
// webhook deliveries, so it runs on a relaxed cadence, same "webhooks carry
// the real-time load" framing as `orders:poll-woo`/`orders:poll-shopify`.
Schedule::command('orders:poll-tiktok')->everyThirtyMinutes();

// Inbound half of eBay's flagship messaging channel (Plan §4.5/§7.3) — no
// webhooks for Trading API member messages either, same cadence as the
// order poller above.
Schedule::command('inbox:poll-ebay-messages')->everyFiveMinutes();

// GDPR deletion grace period (Plan §4.8) — daily is plenty for a 30-day window.
Schedule::command('accounts:purge-deleted')->daily();

// Rolls up yesterday into daily_stats shortly after midnight (Plan §4.6/§9).
Schedule::command('analytics:aggregate-daily')->dailyAt('00:15');

// Rolls today's Ops & Health scalars into ops_health_snapshots for the
// admin Ops page's 30-day trend charts (Plan §8.7.7) — same daily-rollup
// cadence as `analytics:aggregate-daily` above, offset 5 minutes later so
// it isn't competing with it for the same minute.
Schedule::command('ops:record-daily-snapshot')->dailyAt('00:20');

// Morning digest (Plan §4.6) — runs hourly, only acts on teams whose owner
// is currently in their local 7am hour (see the command for the guard).
Schedule::command('notifications:send-morning-digests')->hourly();

// Time-based rule triggers (Plan §8.4) — unfulfilled_after_x/ship_by_deadline.
Schedule::command('orders:check-deadlines')->hourly();

// Custom Pro digest-trigger rules (Plan §4.4) — per-rule daily/weekly cadence.
Schedule::command('rules:send-digests')->hourly();

// low_stock / negative_review triggers (Plan §4.4) — poll-only for now.
Schedule::command('products:poll-woo')->everyThirtyMinutes();
Schedule::command('reviews:poll-woo')->hourly();

// eBay's low_stock/negative_review data sources (Plan §4.4/§7.3/§7.8) — no
// webhooks for either (same "polling is the whole mechanism" posture as
// `orders:poll-ebay`/`inbox:poll-ebay-messages` above), so these run on the
// same cadence as their Woo counterparts.
Schedule::command('products:poll-ebay')->everyThirtyMinutes();
Schedule::command('reviews:poll-ebay')->hourly();

// Admin messaging center (Plan §8.7.5) — dispatches broadcasts once their scheduled_at arrives.
Schedule::command('messaging:send-scheduled-broadcasts')->everyFiveMinutes();

// Multi-currency consolidation (Plan §4.6/§9). Backfill runs right after sync
// so orders stuck null the day their currency pair first appeared catch up
// the same day the rate lands, not a whole cycle later.
Schedule::command('fx:sync-rates')->dailyAt('00:05');
Schedule::command('orders:backfill-base-currency')->dailyAt('00:10');

// Trial lifecycle (Plan §6.3/§6.4).
Schedule::command('trials:send-reminders')->hourly();
Schedule::command('subscriptions:expire-trials')->hourly();

// Support chat (Plan §4.9) — "auto-nudge and auto-close after 7 days idle."
Schedule::command('support:auto-close-idle-threads')->daily();
