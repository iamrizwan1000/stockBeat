<?php

namespace App\Console\Commands;

use App\Actions\Billing\ConvertToBaseCurrencyAction;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * Retries `total_base_currency` for orders ingested before an FX rate for
 * their currency pair existed (Plan §4.6/§9) — without this, an order
 * ingested on day one of a new currency pair would stay `null` forever
 * even after `fx:sync-rates` catches up the next day.
 */
class BackfillOrderBaseCurrency extends Command
{
    protected $signature = 'orders:backfill-base-currency';

    protected $description = 'Retry total_base_currency for orders left null pending an FX rate';

    public function handle(ConvertToBaseCurrencyAction $convert): int
    {
        $updated = Order::query()
            ->whereNull('total_base_currency')
            ->with('team.owner')
            ->get()
            ->filter(function (Order $order) use ($convert) {
                $baseCurrency = $order->team?->owner?->base_currency;

                if ($baseCurrency === null || $order->currency === $baseCurrency) {
                    return false;
                }

                $resolved = $convert->handle($order->total, $order->currency, $baseCurrency, $order->placed_at);

                if ($resolved === null) {
                    return false;
                }

                $order->update(['total_base_currency' => $resolved]);

                return true;
            })
            ->count();

        $this->info("Backfilled {$updated} order(s).");

        return self::SUCCESS;
    }
}
