<?php

namespace App\Console\Commands;

use App\Actions\Rules\RuleEvaluationAction;
use App\Models\Rule;
use App\Models\RuleExecution;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fires the custom Pro `digest` trigger (Plan §4.4) — distinct from the
 * free-tier `SendMorningDigests` preset: this one is per-rule configurable
 * (`controls.digest_frequency`/`digest_time`/`digest_day_of_week`) and goes
 * through the rule's own actions/controls rather than always pushing the
 * team owner. Runs hourly like `SendMorningDigests`; `isDue()` is this
 * command's own guard against double-firing within the same due window —
 * `RuleEvaluationAction`'s hard dedup deliberately doesn't apply to
 * order-less triggers (see its `alreadyFired()`), so this is the only
 * thing standing between an overlapping run and a duplicate send.
 */
class SendRuleDigests extends Command
{
    protected $signature = 'rules:send-digests';

    protected $description = 'Fire digest-trigger rules once per their configured daily/weekly cadence';

    public function handle(RuleEvaluationAction $action): int
    {
        $sent = 0;

        Rule::query()
            ->where('trigger', Rule::TRIGGER_DIGEST)
            ->where('enabled', true)
            ->with('team.owner')
            ->chunkById(100, function ($rules) use ($action, &$sent) {
                foreach ($rules as $rule) {
                    if (! $this->isDue($rule)) {
                        continue;
                    }

                    if ($action->handle($rule, Rule::TRIGGER_DIGEST, null) !== null) {
                        $sent++;
                    }
                }
            });

        $this->info("Fired {$sent} digest rule(s).");

        return self::SUCCESS;
    }

    private function isDue(Rule $rule): bool
    {
        $timezone = $rule->team->owner->timezone ?? 'UTC';
        $now = Carbon::now($timezone);

        [$hour] = array_map('intval', explode(':', (string) ($rule->controls['digest_time'] ?? '07:00')));

        if ($now->hour !== $hour) {
            return false;
        }

        $frequency = $rule->controls['digest_frequency'] ?? 'daily';

        if ($frequency === 'weekly') {
            $dayOfWeek = (int) ($rule->controls['digest_day_of_week'] ?? Carbon::MONDAY);

            if ($now->dayOfWeek !== $dayOfWeek) {
                return false;
            }
        }

        $lastFiredAt = RuleExecution::query()
            ->where('rule_id', $rule->id)
            ->where('trigger', Rule::TRIGGER_DIGEST)
            ->latest('fired_at')
            ->value('fired_at');

        if ($lastFiredAt === null) {
            return true;
        }

        $boundary = $frequency === 'weekly' ? $now->copy()->subDays(6) : $now->copy()->startOfDay();

        return Carbon::parse($lastFiredAt)->lt($boundary);
    }
}
