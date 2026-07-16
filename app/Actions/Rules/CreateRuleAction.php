<?php

namespace App\Actions\Rules;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Models\Rule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Creates a rule, enforcing the plan's `max_rules` limit (Free = preset
 * alerts only / 0 custom rules, Pro = unlimited — Plan §5).
 */
class CreateRuleAction
{
    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Team $team, User $creator, array $data): Rule
    {
        $maxRules = $this->resolveEntitlements->handle($team)['limits']['max_rules'] ?? null;

        if ($maxRules !== null) {
            $currentCount = Rule::query()->where('team_id', $team->id)->count();

            if ($currentCount >= $maxRules) {
                throw ValidationException::withMessages([
                    'trigger' => "You've reached your plan's custom rule limit ({$maxRules}). Upgrade to add more rules.",
                ]);
            }
        }

        return Rule::query()->create([
            'team_id' => $team->id,
            'name' => $data['name'],
            'trigger' => $data['trigger'],
            'conditions' => $data['conditions'] ?? null,
            'actions' => $data['actions'],
            'controls' => $data['controls'] ?? null,
            'enabled' => $data['enabled'] ?? true,
            'created_by' => $creator->id,
        ]);
    }
}
