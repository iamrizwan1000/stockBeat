<?php

namespace App\Actions\Rules;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Models\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Updates a rule. Changing `trigger` to one of `Rule::advancedTriggers()`
 * (Premium-only) goes through the same `advanced_triggers_enabled` gate as
 * `CreateRuleAction` — without this, a Starter/Pro team could bypass the
 * gate entirely by creating a rule with an allowed trigger, then editing
 * it to `order_spike`/`refund_spike`.
 */
class UpdateRuleAction
{
    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Rule $rule, array $data): Rule
    {
        if (isset($data['trigger']) && in_array($data['trigger'], Rule::advancedTriggers(), true)) {
            $limits = $this->resolveEntitlements->handle($rule->team)['limits'];

            if (empty($limits['advanced_triggers_enabled'])) {
                throw ValidationException::withMessages([
                    'trigger' => 'This trigger requires the Premium plan.',
                ]);
            }
        }

        $rule->fill(array_intersect_key($data, array_flip([
            'name', 'trigger', 'conditions', 'actions', 'sound', 'controls', 'enabled',
        ])));
        $rule->save();

        return $rule;
    }
}
