<?php

namespace App\Actions\Billing;

use App\Models\FxRate;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Multi-currency consolidation (Plan §4.6/§9/§17.3). Pulls real daily rates
 * from Frankfurter (ECB-derived, free, no API key) for every base currency
 * a team actually reports in against every currency an order has actually
 * arrived in — not a fixed currency list, so a merchant selling in a
 * currency nobody's used yet just gets a `null` `revenue_base` until the
 * next sync picks it up, rather than a guessed/stale rate.
 */
class SyncFxRatesAction
{
    private const ENDPOINT = 'https://api.frankfurter.dev/v1/latest';

    /**
     * @return int Number of rates upserted
     */
    public function handle(): int
    {
        $bases = User::query()->whereNotNull('base_currency')->distinct()->pluck('base_currency');
        $quotes = Order::query()->whereNotNull('currency')->distinct()->pluck('currency');

        $currencies = $bases->merge($quotes)->unique()->filter()->values();

        if ($currencies->count() < 2) {
            return 0;
        }

        $count = 0;

        foreach ($bases->unique()->values() as $base) {
            $symbols = $currencies->reject(fn (string $c) => $c === $base)->values();

            if ($symbols->isEmpty()) {
                continue;
            }

            $count += $this->syncForBase($base, $symbols->all());
        }

        return $count;
    }

    /**
     * @param  array<int, string>  $symbols
     */
    private function syncForBase(string $base, array $symbols): int
    {
        $response = Http::timeout(10)->get(self::ENDPOINT, [
            'base' => $base,
            'symbols' => implode(',', $symbols),
        ]);

        if ($response->failed()) {
            Log::warning('fx_rates sync failed for base currency', ['base' => $base, 'status' => $response->status()]);

            return 0;
        }

        $date = (string) $response->json('date');
        /** @var array<string, float> $rates */
        $rates = (array) $response->json('rates', []);
        $count = 0;

        foreach ($rates as $quote => $rate) {
            // Not `updateOrCreate()`: its lookup is a plain `where('date', ...)`
            // equality check, which has the same cross-database pitfall as the
            // range query in `ConvertToBaseCurrencyAction` — `whereDate()` here too.
            $existing = FxRate::query()
                ->where('base', $base)
                ->where('quote', $quote)
                ->whereDate('date', $date)
                ->first();

            if ($existing !== null) {
                $existing->update(['rate' => $rate]);
            } else {
                FxRate::query()->create(['base' => $base, 'quote' => $quote, 'date' => $date, 'rate' => $rate]);
            }

            $count++;
        }

        return $count;
    }
}
