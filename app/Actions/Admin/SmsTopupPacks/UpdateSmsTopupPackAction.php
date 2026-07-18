<?php

namespace App\Actions\Admin\SmsTopupPacks;

use App\Actions\Admin\AuditLogAction;
use App\Actions\Billing\ProcessRevenueCatEventAction;
use App\Models\AdminUser;
use App\Models\SmsTopupPack;

/**
 * The pack `key` is immutable after creation — it's the stable identifier
 * both {@see ProcessRevenueCatEventAction} (crediting
 * by product id) and the mobile `sms_topup_packs` catalog rely on — only
 * the display fields plus active/sort_order are editable here.
 */
class UpdateSmsTopupPackAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, SmsTopupPack $pack, array $data): SmsTopupPack
    {
        $before = [
            'name' => $pack->name,
            'sms_credits' => $pack->sms_credits,
            'price_usd' => (string) $pack->price_usd,
            'active' => $pack->active,
            'sort_order' => $pack->sort_order,
        ];

        $pack->update([
            'name' => $data['name'],
            'sms_credits' => $data['sms_credits'],
            'price_usd' => $data['price_usd'],
            'active' => $data['active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        $this->auditLog->handle($admin, 'sms_topup_pack.update', SmsTopupPack::class, $pack->id, $before, [
            'name' => $pack->name,
            'sms_credits' => $pack->sms_credits,
            'price_usd' => (string) $pack->price_usd,
            'active' => $pack->active,
            'sort_order' => $pack->sort_order,
        ]);

        return $pack;
    }
}
