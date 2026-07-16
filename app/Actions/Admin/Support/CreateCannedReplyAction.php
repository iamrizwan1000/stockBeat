<?php

namespace App\Actions\Admin\Support;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\CannedReply;

class CreateCannedReplyAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, string $title, string $body): CannedReply
    {
        $reply = CannedReply::query()->create([
            'title' => $title,
            'body' => $body,
            'created_by' => $admin->id,
        ]);

        $this->auditLog->handle($admin, 'canned_reply.create', CannedReply::class, $reply->id, null, [
            'title' => $reply->title,
        ]);

        return $reply;
    }
}
