<?php

namespace App\Actions\Notifications;

use App\Models\SmsLedger;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Sends a real SMS via Twilio's Messages API (Plan §8.2), now that a
 * Twilio account exists (§15.2). Uses a plain `Http::` POST rather than
 * the `twilio/sdk` package — same convention as the WooCommerce adapter's
 * direct REST calls, and Twilio's Messages API is a single simple POST, not
 * worth a whole SDK dependency for.
 *
 * Credit is only ever debited on a real confirmed send — never for a
 * missing phone number or a failed API call (§17.4: "don't debit credit on
 * failure").
 */
class SendSmsNotificationAction
{
    public function handle(Team $team, User $recipient, string $body): string
    {
        $balance = SmsLedger::currentBalance($team->id);

        if ($balance < 1) {
            return 'insufficient_credit';
        }

        if ($recipient->phone === null) {
            return 'no_phone_number';
        }

        $accountSid = config('services.twilio.account_sid');
        $authToken = config('services.twilio.auth_token');
        $messagingServiceSid = config('services.twilio.messaging_service_sid');

        if (! is_string($accountSid) || $accountSid === '' || ! is_string($authToken) || $authToken === '') {
            return 'not_yet_available';
        }

        $response = Http::asForm()
            ->withBasicAuth($accountSid, $authToken)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                'To' => $recipient->phone,
                'MessagingServiceSid' => $messagingServiceSid,
                'Body' => $body,
            ]);

        if ($response->failed()) {
            return 'failed';
        }

        $this->debitCredit($team, $recipient, $response->json('sid'));

        return 'sent';
    }

    private function debitCredit(Team $team, User $recipient, mixed $messageSid): void
    {
        $balanceAfter = SmsLedger::currentBalance($team->id) - 1;

        SmsLedger::query()->create([
            'team_id' => $team->id,
            'delta' => -1,
            'reason' => SmsLedger::REASON_SEND,
            'balance_after' => $balanceAfter,
            'meta' => ['recipient_user_id' => $recipient->id, 'twilio_sid' => $messageSid],
        ]);
    }
}
