<?php

namespace App\Actions\Billing;

use App\Models\Plan;

/**
 * Returns every active plan's key, name, and limits — the catalog view
 * `ResolveEntitlementsAction` doesn't provide, since that one only ever
 * resolves the calling team's own plan. Backs client-side plan-comparison
 * screens (e.g. the mobile "Compare plans" screen) so their numeric copy
 * can be sourced from `plan_limits` instead of hardcoded strings.
 */
class ListActivePlansAction
{
    /**
     * @return array<int, array{key: string, name: string, limits: array<string, mixed>}>
     */
    public function handle(): array
    {
        return Plan::query()
            ->with('limits')
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (Plan $plan): array => [
                'key' => $plan->key,
                'name' => $plan->name,
                'limits' => $plan->limitsArray(),
            ])
            ->all();
    }
}
