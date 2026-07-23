<?php

namespace App\Actions\Billing;

use App\Models\AiUsageLedger;
use App\Models\Notification;
use App\Models\SmsLedger;
use App\Models\Team;

/**
 * Wraps ResolveEntitlementsAction with the team's current SMS/AI-question/
 * email standing — the full "entitlements" shape both `/me` and
 * `/billing/entitlements` return, kept in one place so the two can't drift
 * apart the way MeController's inline version and a second copy here would
 * have.
 */
class ResolveFullEntitlementsAction
{
    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Team $team): array
    {
        $entitlements = $this->resolveEntitlements->handle($team);

        return [
            ...$entitlements,
            'sms_balance' => SmsLedger::currentBalance($team->id),
            'ai_questions_remaining' => $this->aiQuestionsRemaining($team, $entitlements['limits']['ai_questions_monthly'] ?? null),
            'emails_remaining' => $this->emailsRemaining($team, $entitlements['limits']['email_monthly'] ?? null),
        ];
    }

    private function aiQuestionsRemaining(Team $team, ?int $monthlyLimit): ?int
    {
        $effectiveLimit = AiUsageLedger::effectiveMonthlyLimit($team->id, $monthlyLimit);

        if ($effectiveLimit === null) {
            return null;
        }

        return max($effectiveLimit - AiUsageLedger::questionsUsedThisMonth($team->id), 0);
    }

    /**
     * Unlike SMS/AI, there's no top-up bonus to net in for email — the
     * monthly plan limit is the whole cap.
     */
    private function emailsRemaining(Team $team, ?int $monthlyLimit): ?int
    {
        if ($monthlyLimit === null) {
            return null;
        }

        return max($monthlyLimit - Notification::emailsSentThisMonth($team), 0);
    }
}
