<?php

namespace App\Actions\Admin\Support;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\CannedReply;

class DeleteCannedReplyAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, CannedReply $reply): void
    {
        $before = ['title' => $reply->title];
        $replyId = $reply->id;

        $reply->delete();

        $this->auditLog->handle($admin, 'canned_reply.delete', CannedReply::class, $replyId, $before, null);
    }
}
