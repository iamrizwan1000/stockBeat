<?php

namespace App\Actions\Admin\Messaging;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\Broadcast;

class CreateBroadcastAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, array $data): Broadcast
    {
        $scheduledAt = $data['scheduled_at'] ?? null;

        // No superadmin-only restriction on *composing* or *scheduling* an
        // all-audience broadcast: the real guardrail (Plan §8.7.5
        // "superadmin approval required for all-users sends") is the
        // `approved_by`/`approved_at` gate `SendBroadcastAction` enforces at
        // send time — including when the cron dispatcher
        // (`SendScheduledBroadcasts`) fires a scheduled one unattended. That
        // lets a support-role admin draft/schedule a to-everyone broadcast
        // that simply won't go out until a superadmin approves it.
        $broadcast = Broadcast::query()->create([
            'audience_type' => $data['audience_type'],
            'segment_id' => $data['audience_type'] === Broadcast::AUDIENCE_SEGMENT ? $data['segment_id'] : null,
            'user_id' => $data['audience_type'] === Broadcast::AUDIENCE_USER ? $data['user_id'] : null,
            'channels' => array_values($data['channels']),
            'title' => $data['title'],
            'body' => $data['body'],
            'status' => $scheduledAt !== null ? Broadcast::STATUS_SCHEDULED : Broadcast::STATUS_DRAFT,
            'scheduled_at' => $scheduledAt,
            'created_by' => $admin->id,
        ]);

        $this->auditLog->handle($admin, 'broadcast.create', Broadcast::class, $broadcast->id, null, [
            'audience_type' => $broadcast->audience_type,
            'channels' => $broadcast->channels,
            'title' => $broadcast->title,
            'status' => $broadcast->status,
        ]);

        return $broadcast;
    }
}
