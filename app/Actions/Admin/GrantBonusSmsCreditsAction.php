<?php

namespace App\Actions\Admin;

use App\Models\AdminUser;
use App\Models\SmsLedger;
use App\Models\Team;
use App\Support\Concurrency\IdempotencyGuard;

/**
 * Guarded against an admin double-clicking "Grant" on a slow connection —
 * without this, two rapid calls each independently read the latest balance
 * and add `$credits` on top, genuinely double-granting (e.g. 100 credits
 * granted twice becomes 200, not a display glitch).
 */
class GrantBonusSmsCreditsAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, Team $team, int $credits): ?SmsLedger
    {
        return IdempotencyGuard::once("grant:{$team->id}:sms", 10, function () use ($admin, $team, $credits) {
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
        });
    }
}
