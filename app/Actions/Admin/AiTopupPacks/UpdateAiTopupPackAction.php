<?php

namespace App\Actions\Admin\AiTopupPacks;

use App\Actions\Admin\AuditLogAction;
use App\Actions\Billing\ProcessRevenueCatEventAction;
use App\Models\AdminUser;
use App\Models\AiTopupPack;

/**
 * The pack `key` is immutable after creation — it's the stable identifier
 * both {@see ProcessRevenueCatEventAction} (crediting
 * by product id) and the mobile `ai_topup_packs` catalog rely on — only
 * the display fields plus active/sort_order are editable here.
 */
class UpdateAiTopupPackAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, AiTopupPack $pack, array $data): AiTopupPack
    {
        $before = [
            'name' => $pack->name,
            'ai_questions' => $pack->ai_questions,
            'price_usd' => (string) $pack->price_usd,
            'active' => $pack->active,
            'sort_order' => $pack->sort_order,
        ];

        $pack->update([
            'name' => $data['name'],
            'ai_questions' => $data['ai_questions'],
            'price_usd' => $data['price_usd'],
            'active' => $data['active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        $this->auditLog->handle($admin, 'ai_topup_pack.update', AiTopupPack::class, $pack->id, $before, [
            'name' => $pack->name,
            'ai_questions' => $pack->ai_questions,
            'price_usd' => (string) $pack->price_usd,
            'active' => $pack->active,
            'sort_order' => $pack->sort_order,
        ]);

        return $pack;
    }
}
