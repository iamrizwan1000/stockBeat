<?php

namespace App\Actions\Admin\Support;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\CannedReply;

class UpdateCannedReplyAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, CannedReply $reply, string $title, string $body): CannedReply
    {
        $before = ['title' => $reply->title, 'body' => $reply->body];

        $reply->update(['title' => $title, 'body' => $body]);

        $this->auditLog->handle($admin, 'canned_reply.update', CannedReply::class, $reply->id, $before, [
            'title' => $reply->title,
            'body' => $reply->body,
        ]);

        return $reply;
    }
}
