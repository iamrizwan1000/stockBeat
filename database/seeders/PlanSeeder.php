<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanLimit;
use Illuminate\Database\Seeder;

/**
 * Seeds the Free/Starter/Pro/Premium plans and their limits (Plan §5,
 * revised 2026-07-16 from the original Free/Pro-only model). All values
 * here are defaults only — they're admin-editable at runtime via
 * `plan_limits` (§8.7.3), never hardcoded in application code.
 *
 * `advanced_triggers_enabled` gates only `order_spike`/`refund_spike`
 * (`Rule::advancedTriggers()`) — `low_stock`/`negative_review` are
 * available from Starter up like every other trigger, a deliberate call
 * that they read as basic seller hygiene rather than a Premium-only perk.
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
            PlanLimit::ADVANCED_TRIGGERS_ENABLED => false,
            PlanLimit::AI_ENABLED => false,
            PlanLimit::AI_QUESTIONS_MONTHLY => 0,
            PlanLimit::AI_RULE_BUILDER_ENABLED => false,
            PlanLimit::AI_PROACTIVE_INSIGHTS_ENABLED => false,
        ]);

        $starter = Plan::query()->updateOrCreate(
            ['key' => Plan::STARTER],
            ['name' => 'Starter', 'active' => true],
        );

        $this->syncLimits($starter, [
            PlanLimit::MAX_STORES => 3,
            PlanLimit::MAX_RULES => 5,
            PlanLimit::SMS_MONTHLY => 20,
            PlanLimit::EMAIL_MONTHLY => 250,
            PlanLimit::HISTORY_DAYS => 30,
            PlanLimit::TEAM_SEATS => 1,
            PlanLimit::INBOX_ENABLED => false,
            PlanLimit::ANALYTICS_LEVEL => '7d',
            PlanLimit::WIDGETS_ENABLED => false,
            PlanLimit::ADVANCED_TRIGGERS_ENABLED => false,
            PlanLimit::AI_ENABLED => true,
            PlanLimit::AI_QUESTIONS_MONTHLY => 30,
            PlanLimit::AI_RULE_BUILDER_ENABLED => false,
            PlanLimit::AI_PROACTIVE_INSIGHTS_ENABLED => false,
        ]);

        $pro = Plan::query()->updateOrCreate(
            ['key' => Plan::PRO],
            ['name' => 'Pro', 'active' => true],
        );

        $this->syncLimits($pro, [
            PlanLimit::MAX_STORES => 10,
            PlanLimit::MAX_RULES => null,
            PlanLimit::SMS_MONTHLY => 100,
            PlanLimit::EMAIL_MONTHLY => 1000,
            PlanLimit::HISTORY_DAYS => 365,
            PlanLimit::TEAM_SEATS => 3,
            PlanLimit::INBOX_ENABLED => true,
            PlanLimit::ANALYTICS_LEVEL => 'full',
            PlanLimit::WIDGETS_ENABLED => true,
            PlanLimit::ADVANCED_TRIGGERS_ENABLED => false,
            PlanLimit::AI_ENABLED => true,
            PlanLimit::AI_QUESTIONS_MONTHLY => 150,
            PlanLimit::AI_RULE_BUILDER_ENABLED => true,
            PlanLimit::AI_PROACTIVE_INSIGHTS_ENABLED => false,
        ]);

        $premium = Plan::query()->updateOrCreate(
            ['key' => Plan::PREMIUM],
            ['name' => 'Premium', 'active' => true],
        );

        $this->syncLimits($premium, [
            PlanLimit::MAX_STORES => null,
            PlanLimit::MAX_RULES => null,
            PlanLimit::SMS_MONTHLY => 500,
            PlanLimit::EMAIL_MONTHLY => 5000,
            PlanLimit::HISTORY_DAYS => null,
            PlanLimit::TEAM_SEATS => 10,
            PlanLimit::TRIAL_DAYS => 7,
            PlanLimit::INBOX_ENABLED => true,
            PlanLimit::ANALYTICS_LEVEL => 'full',
            PlanLimit::WIDGETS_ENABLED => true,
            PlanLimit::ADVANCED_TRIGGERS_ENABLED => true,
            PlanLimit::AI_ENABLED => true,
            PlanLimit::AI_QUESTIONS_MONTHLY => 500,
            PlanLimit::AI_RULE_BUILDER_ENABLED => true,
            PlanLimit::AI_PROACTIVE_INSIGHTS_ENABLED => true,
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
