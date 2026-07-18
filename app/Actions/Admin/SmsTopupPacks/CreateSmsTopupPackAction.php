<?php

namespace App\Actions\Admin\SmsTopupPacks;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\SmsTopupPack;

class CreateSmsTopupPackAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, array $data): SmsTopupPack
    {
        $pack = SmsTopupPack::query()->create([
            'key' => $data['key'],
            'name' => $data['name'],
            'sms_credits' => $data['sms_credits'],
            'price_usd' => $data['price_usd'],
            'active' => $data['active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        $this->auditLog->handle($admin, 'sms_topup_pack.create', SmsTopupPack::class, $pack->id, null, [
            'key' => $pack->key,
            'name' => $pack->name,
            'sms_credits' => $pack->sms_credits,
            'price_usd' => (string) $pack->price_usd,
            'active' => $pack->active,
            'sort_order' => $pack->sort_order,
        ]);

        return $pack;
    }
}
