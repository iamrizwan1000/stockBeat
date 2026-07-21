<?php

namespace App\Actions\Admin;

use App\Models\AdminUser;
use App\Models\AiUsageLedger;
use App\Models\Team;

/**
 * Admin-initiated AI question comp (Plan §8.7.2/§8.7.9), mirroring
 * `GrantBonusSmsCreditsAction`'s use of the same `topup_iap` reason for an
 * admin comp (not just a real IAP purchase). Raises the team's *current
 * calendar month* question cap — see `AiUsageLedger`'s docblock for why
 * this doesn't carry into future months as a true non-expiring wallet
 * would.
 */
class GrantBonusAiCreditsAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, Team $team, int $credits): AiUsageLedger
    {
        $bonusBefore = AiUsageLedger::bonusGrantedThisMonth($team->id);

        $entry = AiUsageLedger::query()->create([
            'team_id' => $team->id,
            'delta' => $credits,
            'reason' => AiUsageLedger::REASON_TOPUP_IAP,
            'balance_after' => $bonusBefore + $credits,
            'meta' => ['granted_by_admin_id' => $admin->id],
        ]);

        $this->auditLog->handle($admin, 'customer.grant_bonus_ai_credits', Team::class, $team->id, [
            'bonus_granted_this_month' => $bonusBefore,
        ], [
            'bonus_granted_this_month' => $bonusBefore + $credits,
        ]);

        return $entry;
    }
}
