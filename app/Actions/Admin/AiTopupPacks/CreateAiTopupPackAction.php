<?php

namespace App\Actions\Admin\AiTopupPacks;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\AiTopupPack;

class CreateAiTopupPackAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, array $data): AiTopupPack
    {
        $pack = AiTopupPack::query()->create([
            'key' => $data['key'],
            'name' => $data['name'],
            'ai_questions' => $data['ai_questions'],
            'price_usd' => $data['price_usd'],
            'active' => $data['active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        $this->auditLog->handle($admin, 'ai_topup_pack.create', AiTopupPack::class, $pack->id, null, [
            'key' => $pack->key,
            'name' => $pack->name,
            'ai_questions' => $pack->ai_questions,
            'price_usd' => (string) $pack->price_usd,
            'active' => $pack->active,
            'sort_order' => $pack->sort_order,
        ]);

        return $pack;
    }
}
