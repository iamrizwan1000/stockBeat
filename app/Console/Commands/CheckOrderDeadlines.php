<?php

namespace App\Console\Commands;

use App\Jobs\RuleEvaluationJob;
use App\Models\Order;
use App\Models\Rule;
use Illuminate\Console\Command;

/**
 * Re-evaluates the two scheduler-driven rule triggers (Plan §8.4:
 * "scheduler scans orders with due check_at timestamps") — unfulfilled_after_x
 * and ship_by_deadline. `check_at` is set at ingest and cleared once an
 * order reaches a terminal state (§8.4/IngestOrderAction), so this scan
 * only ever touches orders still awaiting fulfillment. The actual per-rule
 * `threshold_hours` gate lives in RuleEvaluationAction — dispatching both
 * triggers here is a no-op for any rule whose threshold hasn't elapsed yet.
 */
class CheckOrderDeadlines extends Command
{
    protected $signature = 'orders:check-deadlines';

    protected $description = 'Dispatch unfulfilled_after_x / ship_by_deadline rule evaluation for orders still awaiting fulfillment';

    public function handle(): int
    {
        $count = 0;

        Order::query()
            ->whereNotNull('check_at')
            ->where('check_at', '<=', now())
            ->chunkById(200, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_UNFULFILLED_AFTER_X);
                    RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_SHIP_BY_DEADLINE);
                    $count++;
                }
            });

        $this->info("Checked {$count} order(s) for time-based rule triggers.");

        return self::SUCCESS;
    }
}
