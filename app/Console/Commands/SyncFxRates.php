<?php

namespace App\Console\Commands;

use App\Actions\Billing\SyncFxRatesAction;
use Illuminate\Console\Command;

/**
 * Daily FX rate sync (Plan §4.6/§9). Runs before `orders:backfill-base-currency`
 * in the schedule so newly-synced pairs get picked up the same day.
 */
class SyncFxRates extends Command
{
    protected $signature = 'fx:sync-rates';

    protected $description = 'Sync daily FX rates for every base/quote currency pair actually in use';

    public function handle(SyncFxRatesAction $action): int
    {
        $count = $action->handle();

        $this->info("Synced {$count} FX rate(s).");

        return self::SUCCESS;
    }
}
