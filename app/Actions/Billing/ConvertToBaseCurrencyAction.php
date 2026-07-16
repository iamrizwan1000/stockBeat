<?php

namespace App\Actions\Billing;

use App\Models\FxRate;
use Carbon\CarbonInterface;

/**
 * Converts an amount into a team's base currency using the closest FX rate
 * on or before the given date (Plan §4.6/§9) — never today's rate applied
 * retroactively to an old order, and never a rate from *after* the order
 * happened. Returns `null` (never a fabricated 1:1 guess) when no rate is
 * available yet for that currency pair — same honest-gap convention as
 * `IngestOrderAction` used before this table existed.
 *
 * Uses `whereDate()`, not a plain `where('date', '<=', ...)` — Eloquent's
 * `date` cast round-trips through a full `Y-m-d H:i:s` string on save, which
 * MariaDB truncates to a bare date on storage but SQLite (dynamically typed,
 * no column coercion) stores verbatim; a lexical `<=` against a bare
 * `Y-m-d` string then silently excludes same-day rows under SQLite only.
 * `whereDate()` generates driver-correct SQL either way.
 */
class ConvertToBaseCurrencyAction
{
    public function handle(float $amount, string $from, string $to, CarbonInterface $onOrBefore): ?float
    {
        if ($from === $to) {
            return $amount;
        }

        $rate = FxRate::query()
            ->where('base', $to)
            ->where('quote', $from)
            ->whereDate('date', '<=', $onOrBefore->toDateString())
            ->orderByDesc('date')
            ->value('rate');

        if ($rate === null || (float) $rate <= 0.0) {
            return null;
        }

        return round($amount / (float) $rate, 2);
    }
}
