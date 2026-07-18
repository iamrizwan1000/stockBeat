<?php

namespace App\Actions\Admin\Messaging;

use App\Actions\Admin\AuditLogAction;
use App\Jobs\SendBroadcastToRecipientJob;
use App\Models\AdminUser;
use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the audience and dispatches one queued job per (recipient,
 * channel) — Plan §8.7.5. Guardrail: an `audience_type=all` send is refused
 * until `approved_by`/`approved_at` are already set — via a superadmin's
 * separate `POST .../approve` call (`ApproveBroadcastAction`), never as a
 * side effect of this action. Segmented and single-user sends carry no
 * such risk and bypass the gate entirely.
 */
class SendBroadcastAction
{
    public function __construct(
        private readonly ResolveSegmentAudienceAction $resolveAudience,
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, Broadcast $broadcast): Broadcast
    {
        if (in_array($broadcast->status, [Broadcast::STATUS_SENDING, Broadcast::STATUS_SENT], true)) {
            throw ValidationException::withMessages(['broadcast' => 'This broadcast has already been sent.']);
        }

        if ($broadcast->audience_type === Broadcast::AUDIENCE_ALL && $broadcast->approved_by === null) {
            throw ValidationException::withMessages(['broadcast' => 'This broadcast targets all users and needs superadmin approval before it can be sent.']);
        }

        $recipients = $this->resolveRecipients($broadcast);

        $broadcast->update([
            'status' => Broadcast::STATUS_SENDING,
            'stats' => ['recipients_total' => $recipients->count(), 'channels' => $broadcast->channels],
        ]);

        foreach ($recipients as $recipient) {
            foreach ($broadcast->channels as $channel) {
                SendBroadcastToRecipientJob::dispatch($broadcast->id, $recipient->id, $channel)
                    ->onQueue($this->queueForChannel($channel));
            }
        }

        $broadcast->update(['status' => Broadcast::STATUS_SENT, 'sent_at' => now()]);

        $this->auditLog->handle($admin, 'broadcast.send', Broadcast::class, $broadcast->id, null, [
            'audience_type' => $broadcast->audience_type,
            'recipients_total' => $recipients->count(),
        ]);

        return $broadcast->fresh();
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(Broadcast $broadcast): Collection
    {
        return match ($broadcast->audience_type) {
            Broadcast::AUDIENCE_ALL => $this->resolveAudience->handle(null)->get(),
            Broadcast::AUDIENCE_SEGMENT => $this->resolveAudience->handle($broadcast->segment?->filters)->get(),
            Broadcast::AUDIENCE_USER => $broadcast->user !== null ? collect([$broadcast->user]) : collect(),
            default => collect(),
        };
    }

    /**
     * Named per-channel queues (Plan §15.1: `notify-push`/`notify-email`/`notify-sms`)
     * so a slow email provider can never delay a push send or vice versa. Banner
     * is in-app-only (writes a `Notification` row, no external API call) — routed
     * alongside push since both are near-instant, low-cost sends.
     */
    private function queueForChannel(string $channel): string
    {
        return match ($channel) {
            Broadcast::CHANNEL_EMAIL => 'notify-email',
            default => 'notify-push',
        };
    }
}
