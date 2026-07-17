<?php

namespace App\Console\Commands;

use App\Models\SupportThread;
use Illuminate\Console\Command;

/**
 * Auto-closes support threads idle for 7+ days (Plan §4.9: "Status states:
 * open → awaiting-user → resolved; auto-nudge and auto-close after 7 days
 * idle."). Only the close half is implemented here — auto-nudge (a
 * reminder message before the close) isn't built. A user's own next
 * message still reopens a resolved thread as normal
 * (`SendUserSupportMessageAction`), so closing early costs nothing.
 */
class AutoCloseIdleSupportThreads extends Command
{
    private const IDLE_DAYS = 7;

    protected $signature = 'support:auto-close-idle-threads';

    protected $description = 'Resolve support threads with no activity for 7+ days';

    public function handle(): int
    {
        $cutoff = now()->subDays(self::IDLE_DAYS);

        $closed = SupportThread::query()
            ->where('status', '!=', SupportThread::STATUS_RESOLVED)
            ->where(function ($query) use ($cutoff) {
                $query->where('last_message_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff) {
                        $query->whereNull('last_message_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->update(['status' => SupportThread::STATUS_RESOLVED]);

        $this->info("Auto-closed {$closed} idle support thread(s).");

        return self::SUCCESS;
    }
}
