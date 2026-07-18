<?php

namespace App\Console\Commands;

use App\Actions\Admin\Messaging\SendBroadcastAction;
use App\Models\Broadcast;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Dispatches due scheduled broadcasts (Plan §8.7.5 "schedule for later").
 * Acts as the broadcast's own creator for the audit trail — there's no
 * human in the loop at cron time. An all-audience broadcast whose
 * `approved_by` hasn't been stamped by a superadmin yet (`SendBroadcastAction`'s
 * approval gate) is expected to fail here — caught and logged per-broadcast
 * below — and stays `scheduled` until a superadmin approves it and this
 * command next runs.
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
