<?php

namespace App\Actions\Rules;

use App\Models\Rule;
use App\Models\RuleExecution;

/**
 * "Send me a sample now" (Plan §4.4): really dispatches the rule's actions
 * immediately, bypassing conditions/cooldown/quiet-hours — it's meant to
 * prove the notification channels actually work. Always logs with
 * `order_id = null` so it can be pressed repeatedly without colliding with
 * the (rule_id, order_id, trigger) dedup constraint (Plan §8.4).
 */
class TestFireRuleAction
{
    public function __construct(
        private readonly DispatchRuleActionsAction $dispatchActions,
    ) {}

    public function handle(Rule $rule): RuleExecution
    {
        $actionsResult = $this->dispatchActions->handle($rule, $rule->actions, null);

        return RuleExecution::query()->create([
            'rule_id' => $rule->id,
            'order_id' => null,
            'trigger' => 'test_fire',
            'actions_result' => $actionsResult,
            'fired_at' => now(),
        ]);
    }
}
