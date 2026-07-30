<?php

namespace App\Actions\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Sends a real SMS via the same Twilio Messages API call as
 * `SendSmsNotificationAction`, but for a direct admin-to-customer
 * communication (e.g. notifying a customer about a bonus credit grant) —
 * deliberately has no `Team`/`SmsLedger` involvement at all, so it never
 * checks or debits the customer's own SMS credit balance. Reuses the same
 * `services.twilio.messaging_service_sid` sender pool as automated rule
 * SMS; the message body itself should make clear it's a support/admin
 * message, not an automated rule alert, since both currently arrive from
 * the same sender.
 */
class SendAdminSmsNotificationAction
{
    public function handle(User $recipient, string $body): string
    {
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

        return $response->failed() ? 'failed' : 'sent';
    }
}
