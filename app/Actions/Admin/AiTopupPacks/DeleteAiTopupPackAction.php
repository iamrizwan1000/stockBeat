<?php

namespace App\Actions\Admin\AiTopupPacks;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\AiTopupPack;

class DeleteAiTopupPackAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, AiTopupPack $pack): void
    {
        $before = ['key' => $pack->key, 'name' => $pack->name];
        $packId = $pack->id;

        $pack->delete();

        $this->auditLog->handle($admin, 'ai_topup_pack.delete', AiTopupPack::class, $packId, $before, null);
    }
}
