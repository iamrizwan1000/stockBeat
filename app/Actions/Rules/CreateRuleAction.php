<?php

namespace App\Actions\Rules;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Models\Notification;
use App\Models\PaywallHit;
use App\Models\PlanLimit;
use App\Models\Rule;
use App\Models\Team;
use App\Models\User;
use App\Support\Concurrency\IdempotencyGuard;
use Illuminate\Validation\ValidationException;

/**
 * Creates a rule, enforcing the plan's `max_rules` limit (Free = preset
 * alerts only / 0 custom rules, Starter+ = a real quota or unlimited —
 * Plan §5) and, separately, the `advanced_triggers_enabled` gate on
 * `Rule::advancedTriggers()` (Premium-only).
 *
 * Guarded against a double-tap creating two identical rules — unlike a
 * one-time duplicate (a message, a grant), a duplicate rule keeps firing
 * *every time it matches, forever*, doubling every future notification for
 * that trigger, not just a one-off annoyance. Keyed by the exact submitted
 * payload (not just team+trigger), so submitting a genuinely different
 * rule moments later is never blocked.
 */
class CreateRuleAction
{
    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Team $team, User $creator, array $data): ?Rule
    {
        $limits = $this->resolveEntitlements->handle($team)['limits'];
        $maxRules = $limits['max_rules'] ?? null;

        if ($maxRules !== null) {
            $currentCount = Rule::query()->where('team_id', $team->id)->count();

            if ($currentCount >= $maxRules) {
                PaywallHit::log($team, PlanLimit::MAX_RULES);

                throw ValidationException::withMessages([
                    'trigger' => "You've reached your plan's custom rule limit ({$maxRules}). Upgrade to add more rules.",
                ]);
            }
        }

        if (in_array($data['trigger'], Rule::advancedTriggers(), true) && empty($limits['advanced_triggers_enabled'])) {
            PaywallHit::log($team, PlanLimit::ADVANCED_TRIGGERS_ENABLED);

            throw ValidationException::withMessages([
                'trigger' => 'This trigger requires the Premium plan.',
            ]);
        }

        if ($data['trigger'] === Rule::TRIGGER_AI_INSIGHT && empty($limits['ai_proactive_insights_enabled'])) {
            PaywallHit::log($team, PlanLimit::AI_PROACTIVE_INSIGHTS_ENABLED);

            throw ValidationException::withMessages([
                'trigger' => 'Proactive AI Insights requires the Premium plan.',
            ]);
        }

        $lockKey = 'create-rule:'.$team->id.':'.md5(json_encode($data) ?: '');

        return IdempotencyGuard::once($lockKey, 10, fn () => Rule::query()->create([
            'team_id' => $team->id,
            'name' => $data['name'],
            'trigger' => $data['trigger'],
            'conditions' => $data['conditions'] ?? null,
            'actions' => $data['actions'],
            'sound' => $data['sound'] ?? null,
            'priority' => $data['priority'] ?? Notification::PRIORITY_NORMAL,
            'controls' => $data['controls'] ?? null,
            'enabled' => $data['enabled'] ?? true,
            'created_by' => $creator->id,
        ]));
    }
}
