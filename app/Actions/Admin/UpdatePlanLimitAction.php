<?php

namespace App\Actions\Admin;

use App\Models\AdminUser;
use App\Models\PlanLimit;

/**
 * Plan §8.7.3: every `plan_limits` value is live-editable — the mobile
 * app's next `/me` call reflects it, no app release needed.
 */
class UpdatePlanLimitAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, PlanLimit $limit, mixed $rawValue): PlanLimit
    {
        $before = ['value' => $limit->value];
        $value = $this->coerce($limit->key, $rawValue);

        $limit->value = $value;
        $limit->updated_by = $admin->id;
        $limit->save();

        $this->auditLog->handle($admin, 'plan_limit.update', PlanLimit::class, $limit->id, $before, [
            'value' => $value,
        ]);

        return $limit;
    }

    private function coerce(string $key, mixed $rawValue): mixed
    {
        return match ($key) {
            PlanLimit::INBOX_ENABLED, PlanLimit::WIDGETS_ENABLED, PlanLimit::ADVANCED_TRIGGERS_ENABLED,
            PlanLimit::AI_ENABLED, PlanLimit::AI_RULE_BUILDER_ENABLED, PlanLimit::AI_PROACTIVE_INSIGHTS_ENABLED => filter_var($rawValue, FILTER_VALIDATE_BOOLEAN),
            PlanLimit::ANALYTICS_LEVEL => (string) $rawValue,
            default => ($rawValue === '' || $rawValue === null) ? null : (int) $rawValue,
        };
    }
}
