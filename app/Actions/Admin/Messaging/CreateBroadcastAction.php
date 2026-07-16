<?php

namespace App\Actions\Admin\Messaging;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\Broadcast;
use Illuminate\Validation\ValidationException;

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

        // A scheduled all-audience broadcast fires unattended later (the
        // cron dispatcher, not a human clicking "Send"), so the superadmin
        // guardrail (Plan §8.7.5) must be enforced now — an immediate
        // draft/manual-send broadcast is instead checked at send time
        // (`SendBroadcastAction`), where a rejection is seen right away.
        if ($scheduledAt !== null && $data['audience_type'] === Broadcast::AUDIENCE_ALL && $admin->role !== AdminUser::ROLE_SUPERADMIN) {
            throw ValidationException::withMessages(['audience_type' => 'Only a superadmin can schedule a send to all users.']);
        }

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
