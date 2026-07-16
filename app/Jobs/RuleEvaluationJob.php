<?php

namespace App\Jobs;

use App\Actions\Rules\RuleEvaluationAction;
use App\Models\Order;
use App\Models\Rule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Loads a team's enabled rules for one trigger and evaluates each against
 * the order (Plan §8.4: "Order events dispatch RuleEvaluationJob").
 */
class RuleEvaluationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $orderId,
        public readonly string $trigger,
    ) {
        $this->onQueue('rules');
    }

    public function handle(RuleEvaluationAction $action): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null) {
            return;
        }

        Rule::query()
            ->where('team_id', $order->team_id)
            ->where('trigger', $this->trigger)
            ->where('enabled', true)
            ->each(fn (Rule $rule) => $action->handle($rule, $this->trigger, $order));
    }
}
