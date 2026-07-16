<?php

namespace App\Actions\Notifications;

use App\Models\SmsLedger;
use App\Models\Team;

/**
 * Checks SMS credit but never actually sends — Twilio isn't provisioned
 * yet (Plan §15.2). Credit is never debited for a send that didn't happen
 * (§17.4: "don't debit credit on failure").
 */
class SendSmsNotificationAction
{
    public function handle(Team $team): string
    {
        $balance = SmsLedger::currentBalance($team->id);

        if ($balance < 1) {
            return 'insufficient_credit';
        }

        return 'not_yet_available';
    }
}
