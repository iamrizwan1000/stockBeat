<?php

namespace App\Actions\Admin\Promotions;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\PromoCampaign;

class CreatePromoCampaignAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, array $data): PromoCampaign
    {
        $campaign = PromoCampaign::query()->create([
            'name' => $data['name'],
            'type' => $data['type'],
            'store_ref' => $data['store_ref'] ?? null,
            'config' => $data['config'] ?? [],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'created_by' => $admin->id,
        ]);

        $this->auditLog->handle($admin, 'promo_campaign.create', PromoCampaign::class, $campaign->id, null, [
            'name' => $campaign->name,
            'type' => $campaign->type,
            'config' => $campaign->config,
        ]);

        return $campaign;
    }
}
