<?php

namespace App\Actions\Rules;

use App\Models\Rule;

class UpdateRuleAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Rule $rule, array $data): Rule
    {
        $rule->fill(array_intersect_key($data, array_flip([
            'name', 'trigger', 'conditions', 'actions', 'controls', 'enabled',
        ])));
        $rule->save();

        return $rule;
    }
}
