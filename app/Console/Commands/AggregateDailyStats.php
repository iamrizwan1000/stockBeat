<?php

namespace App\Console\Commands;

use App\Actions\Analytics\AggregateDailyStatsAction;
use App\Models\StoreConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Rolls up yesterday's orders into `daily_stats` for every connection (Plan
 * §4.6/§9) — historical analytics reads from this table instead of
 * re-scanning `orders`. Accepts `--date=` for backfill/manual runs.
 */
class AggregateDailyStats extends Command
{
    protected $signature = 'analytics:aggregate-daily {--date= : Y-m-d date to aggregate, defaults to yesterday}';

    protected $description = "Roll up a day's orders per connection into daily_stats";

    public function handle(AggregateDailyStatsAction $action): int
    {
        $date = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))
            : now()->subDay();

        $connections = StoreConnection::query()->get();

        foreach ($connections as $connection) {
            $action->handle($connection, $date);
        }

        $this->info("Aggregated daily stats for {$connections->count()} connection(s) on {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
