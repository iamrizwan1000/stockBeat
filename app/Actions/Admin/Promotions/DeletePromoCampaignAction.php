<?php

namespace App\Actions\Admin\Promotions;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\PromoCampaign;

class DeletePromoCampaignAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, PromoCampaign $campaign): void
    {
        $this->auditLog->handle($admin, 'promo_campaign.delete', PromoCampaign::class, $campaign->id, [
            'name' => $campaign->name,
            'type' => $campaign->type,
        ], null);

        $campaign->delete();
    }
}
