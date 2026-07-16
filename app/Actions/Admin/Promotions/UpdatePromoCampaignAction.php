<?php

namespace App\Actions\Admin\Promotions;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\PromoCampaign;

class UpdatePromoCampaignAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AdminUser $admin, PromoCampaign $campaign, array $data): PromoCampaign
    {
        $before = [
            'name' => $campaign->name,
            'type' => $campaign->type,
            'config' => $campaign->config,
            'starts_at' => $campaign->starts_at?->toIso8601String(),
            'ends_at' => $campaign->ends_at?->toIso8601String(),
        ];

        $campaign->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'store_ref' => $data['store_ref'] ?? null,
            'config' => $data['config'] ?? [],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ]);

        $this->auditLog->handle($admin, 'promo_campaign.update', PromoCampaign::class, $campaign->id, $before, [
            'name' => $campaign->name,
            'type' => $campaign->type,
            'config' => $campaign->config,
            'starts_at' => $campaign->starts_at?->toIso8601String(),
            'ends_at' => $campaign->ends_at?->toIso8601String(),
        ]);

        return $campaign;
    }
}
