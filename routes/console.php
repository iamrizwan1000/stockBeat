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

// GDPR deletion grace period (Plan §4.8) — daily is plenty for a 30-day window.
Schedule::command('accounts:purge-deleted')->daily();

// Rolls up yesterday into daily_stats shortly after midnight (Plan §4.6/§9).
Schedule::command('analytics:aggregate-daily')->dailyAt('00:15');

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
