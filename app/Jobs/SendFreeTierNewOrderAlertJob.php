<?php

namespace App\Jobs;

use App\Actions\Notifications\SendFreeTierNewOrderAlertAction;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Free-tier "new order push" preset, including the "locked-teaser"
 * conversion mechanic for high-value orders (Plan §4.4/§4.11). Dispatched
 * by `IngestOrderAction` alongside `RuleEvaluationJob`'s `new_order`/
 * `high_value_order` triggers on every genuinely new order — same "queue
 * it, don't block the ingest transaction on a push send" reasoning.
 * `SendFreeTierNewOrderAlertAction` itself no-ops for every paid plan.
 */
class SendFreeTierNewOrderAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $orderId,
    ) {
        $this->onQueue('rules');
    }

    public function handle(SendFreeTierNewOrderAlertAction $action): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null) {
            return;
        }

        $action->handle($order);
    }
}
