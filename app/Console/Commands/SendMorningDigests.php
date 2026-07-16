<?php

namespace App\Console\Commands;

use App\Actions\Analytics\SendMorningDigestAction;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Runs hourly and sends the morning digest (Plan §4.6) to any team whose
 * owner's local time is currently in the 7am hour and who hasn't already
 * been sent one today — `teams.last_digest_sent_at` is the per-team guard
 * against double-sends within that hour-long scheduling window.
 */
class SendMorningDigests extends Command
{
    private const DIGEST_HOUR = 7;

    protected $signature = 'notifications:send-morning-digests';

    protected $description = "Send each team owner yesterday's order summary once it's ~7am their time";

    public function handle(SendMorningDigestAction $action): int
    {
        $sent = 0;

        Team::query()->with('owner')->chunk(100, function ($teams) use ($action, &$sent) {
            foreach ($teams as $team) {
                $timezone = $team->owner->timezone ?? 'UTC';
                $localNow = Carbon::now($timezone);

                if ($localNow->hour !== self::DIGEST_HOUR) {
                    continue;
                }

                if ($team->last_digest_sent_at !== null && $team->last_digest_sent_at->timezone($timezone)->isSameDay($localNow)) {
                    continue;
                }

                $action->handle($team, $localNow->copy()->subDay());
                $team->update(['last_digest_sent_at' => now()]);
                $sent++;
            }
        });

        $this->info("Sent {$sent} morning digest(s).");

        return self::SUCCESS;
    }
}
