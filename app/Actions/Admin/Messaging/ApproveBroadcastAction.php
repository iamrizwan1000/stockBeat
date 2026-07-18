<?php

namespace App\Actions\Admin\Messaging;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\Broadcast;
use Illuminate\Validation\ValidationException;

/**
 * The real send-approval gate (Plan §8.7.5 audit gap #2): stamps
 * `approved_by`/`approved_at` on an `audience_type=all` broadcast, which
 * `SendBroadcastAction` then requires before it will send. Deliberately a
 * separate, superadmin-only, audit-logged step — not something a send
 * attempt sets as a side effect — so a support-role admin can compose and
 * schedule an all-users broadcast, but only a superadmin's explicit
 * approval lets it actually go out.
 */
class ApproveBroadcastAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, Broadcast $broadcast): Broadcast
    {
        if ($admin->role !== AdminUser::ROLE_SUPERADMIN) {
            throw ValidationException::withMessages(['broadcast' => 'Only a superadmin can approve an all-users broadcast.']);
        }

        if ($broadcast->audience_type !== Broadcast::AUDIENCE_ALL) {
            throw ValidationException::withMessages(['broadcast' => 'Only all-users broadcasts require approval.']);
        }

        if (in_array($broadcast->status, [Broadcast::STATUS_SENDING, Broadcast::STATUS_SENT], true)) {
            throw ValidationException::withMessages(['broadcast' => 'This broadcast has already been sent.']);
        }

        $broadcast->update(['approved_by' => $admin->id, 'approved_at' => now()]);

        $this->auditLog->handle($admin, 'broadcast.approve', Broadcast::class, $broadcast->id, null, [
            'audience_type' => $broadcast->audience_type,
        ]);

        return $broadcast->fresh();
    }
}
