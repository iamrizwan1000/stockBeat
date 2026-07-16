<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanLimit;
use Illuminate\Database\Seeder;

/**
 * Seeds the Free/Pro plans and their limits (Plan §5). All values here are
 * defaults only — they're admin-editable at runtime via `plan_limits`
 * (§8.7.3), never hardcoded in application code.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $free = Plan::query()->updateOrCreate(
            ['key' => Plan::FREE],
            ['name' => 'Free', 'active' => true],
        );

        $this->syncLimits($free, [
            PlanLimit::MAX_STORES => 1,
            PlanLimit::MAX_RULES => 0,
            PlanLimit::SMS_MONTHLY => 0,
            PlanLimit::EMAIL_MONTHLY => 25,
            PlanLimit::HISTORY_DAYS => 7,
            PlanLimit::TEAM_SEATS => 1,
            PlanLimit::INBOX_ENABLED => false,
            PlanLimit::ANALYTICS_LEVEL => 'today',
            PlanLimit::WIDGETS_ENABLED => false,
        ]);

        $pro = Plan::query()->updateOrCreate(
            ['key' => Plan::PRO],
            ['name' => 'Pro', 'active' => true],
        );

        $this->syncLimits($pro, [
            PlanLimit::MAX_STORES => null,
            PlanLimit::MAX_RULES => null,
            PlanLimit::SMS_MONTHLY => 100,
            PlanLimit::EMAIL_MONTHLY => 1000,
            PlanLimit::HISTORY_DAYS => 365,
            PlanLimit::TEAM_SEATS => 3,
            PlanLimit::TRIAL_DAYS => 7,
            PlanLimit::INBOX_ENABLED => true,
            PlanLimit::ANALYTICS_LEVEL => 'full',
            PlanLimit::WIDGETS_ENABLED => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function syncLimits(Plan $plan, array $limits): void
    {
        foreach ($limits as $key => $value) {
            PlanLimit::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'key' => $key],
                ['value' => $value],
            );
        }
    }
}
