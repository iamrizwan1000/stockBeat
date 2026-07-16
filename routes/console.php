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
