<?php

namespace App\Actions\Admin\SmsTopupPacks;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\SmsTopupPack;

class DeleteSmsTopupPackAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, SmsTopupPack $pack): void
    {
        $before = ['key' => $pack->key, 'name' => $pack->name];
        $packId = $pack->id;

        $pack->delete();

        $this->auditLog->handle($admin, 'sms_topup_pack.delete', SmsTopupPack::class, $packId, $before, null);
    }
}
