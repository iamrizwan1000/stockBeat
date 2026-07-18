<?php

namespace App\Console\Commands;

use App\Actions\Admin\RecordOpsHealthSnapshotAction;
use Illuminate\Console\Command;

/**
 * Rolls today's Ops & Health scalars into `ops_health_snapshots` (Plan
 * §8.7.7 gap #3) — same "roll up once daily" shape as
 * `analytics:aggregate-daily`, just app-wide instead of per-connection.
 */
class RecordOpsHealthSnapshot extends Command
{
    protected $signature = 'ops:record-daily-snapshot';

    protected $description = "Roll up today's Ops & Health metrics into ops_health_snapshots for 30-day trending";

    public function handle(RecordOpsHealthSnapshotAction $action): int
    {
        $snapshot = $action->handle();

        $this->info("Recorded Ops & Health snapshot for {$snapshot->date->toDateString()}.");

        return self::SUCCESS;
    }
}
