<?php

namespace App\Console\Commands;

use App\Actions\Admin\Messaging\SendBroadcastAction;
use App\Models\Broadcast;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Dispatches due scheduled broadcasts (Plan §8.7.5 "schedule for later").
 * Acts as the broadcast's own creator for the audit trail — there's no
 * human in the loop at cron time, and `CreateBroadcastAction` already
 * refused to schedule an all-audience broadcast for anyone but a
 * superadmin, so this can never be unexpectedly blocked here.
 */
class SendScheduledBroadcasts extends Command
{
    protected $signature = 'messaging:send-scheduled-broadcasts';

    protected $description = 'Send broadcasts whose scheduled_at has arrived';

    public function handle(SendBroadcastAction $action): int
    {
        $sent = 0;

        Broadcast::query()
            ->where('status', Broadcast::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->with('createdBy')
            ->chunkById(50, function ($broadcasts) use ($action, &$sent) {
                foreach ($broadcasts as $broadcast) {
                    try {
                        $action->handle($broadcast->createdBy, $broadcast);
                        $sent++;
                    } catch (ValidationException $exception) {
                        $this->error("Broadcast {$broadcast->id} failed to send: ".$exception->getMessage());
                    }
                }
            });

        $this->info("Sent {$sent} scheduled broadcast(s).");

        return self::SUCCESS;
    }
}
