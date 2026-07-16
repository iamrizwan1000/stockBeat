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
 * channel) — Plan §8.7.5. Guardrail: only a superadmin may send to
 * `audience_type=all` ("superadmin approval required for all-users sends");
 * enforced here as a role check at send time rather than a separate
 * approve/pending workflow — simpler, and the act of a superadmin sending
 * *is* the approval, recorded via `approved_by`.
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

        if ($broadcast->audience_type === Broadcast::AUDIENCE_ALL && $admin->role !== AdminUser::ROLE_SUPERADMIN) {
            throw ValidationException::withMessages(['broadcast' => 'Only a superadmin can send to all users.']);
        }

        $recipients = $this->resolveRecipients($broadcast);

        $broadcast->update([
            'status' => Broadcast::STATUS_SENDING,
            'approved_by' => $broadcast->audience_type === Broadcast::AUDIENCE_ALL ? $admin->id : null,
            'stats' => ['recipients_total' => $recipients->count(), 'channels' => $broadcast->channels],
        ]);

        foreach ($recipients as $recipient) {
            foreach ($broadcast->channels as $channel) {
                SendBroadcastToRecipientJob::dispatch($broadcast->id, $recipient->id, $channel);
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
}
