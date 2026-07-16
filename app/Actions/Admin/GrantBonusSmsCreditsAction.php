<?php

namespace App\Actions\Admin;

use App\Models\AdminUser;
use App\Models\SmsLedger;
use App\Models\Team;

class GrantBonusSmsCreditsAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, Team $team, int $credits): SmsLedger
    {
        $currentBalance = (int) (SmsLedger::query()->where('team_id', $team->id)->latest('id')->value('balance_after') ?? 0);
        $newBalance = $currentBalance + $credits;

        $entry = SmsLedger::query()->create([
            'team_id' => $team->id,
            'delta' => $credits,
            'reason' => SmsLedger::REASON_TOPUP_IAP,
            'balance_after' => $newBalance,
            'meta' => ['granted_by_admin_id' => $admin->id],
        ]);

        $this->auditLog->handle($admin, 'customer.grant_bonus_sms_credits', Team::class, $team->id, [
            'balance' => $currentBalance,
        ], [
            'balance' => $newBalance,
        ]);

        return $entry;
    }
}
