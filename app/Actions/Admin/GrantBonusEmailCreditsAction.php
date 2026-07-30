<?php

namespace App\Actions\Admin;

use App\Models\AdminUser;
use App\Models\EmailUsageLedger;
use App\Models\Team;
use App\Support\Concurrency\IdempotencyGuard;

/**
 * Admin-initiated email quota comp, mirroring `GrantBonusAiCreditsAction`'s
 * use of the `topup_iap` reason for an admin comp rather than a real
 * purchase. Raises the team's *current calendar month* email cap — see
 * `EmailUsageLedger`'s docblock for why this doesn't carry into future
 * months as a true non-expiring wallet would.
 *
 * Guarded against a double-click the same way `GrantBonusSmsCreditsAction`
 * is — see its docblock.
 */
class GrantBonusEmailCreditsAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, Team $team, int $credits): ?EmailUsageLedger
    {
        return IdempotencyGuard::once("grant:{$team->id}:email", 10, function () use ($admin, $team, $credits) {
            $bonusBefore = EmailUsageLedger::bonusGrantedThisMonth($team->id);

            $entry = EmailUsageLedger::query()->create([
                'team_id' => $team->id,
                'delta' => $credits,
                'reason' => EmailUsageLedger::REASON_TOPUP_IAP,
                'balance_after' => $bonusBefore + $credits,
                'meta' => ['granted_by_admin_id' => $admin->id],
            ]);

            $this->auditLog->handle($admin, 'customer.grant_bonus_email_credits', Team::class, $team->id, [
                'bonus_granted_this_month' => $bonusBefore,
            ], [
                'bonus_granted_this_month' => $bonusBefore + $credits,
            ]);

            return $entry;
        });
    }
}
