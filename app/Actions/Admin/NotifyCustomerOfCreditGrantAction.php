<?php

namespace App\Actions\Admin;

use App\Actions\Notifications\SendAdminSmsNotificationAction;
use App\Actions\Notifications\SendPushNotificationAction;
use App\Mail\AdminCreditGrantMail;
use App\Models\AdminUser;
use App\Models\Notification;
use App\Models\Team;
use App\Models\User;
use App\Support\Concurrency\IdempotencyGuard;
use Illuminate\Support\Facades\Mail;

/**
 * Notifies a customer that an admin granted them bonus SMS/AI/email
 * credits, over whichever of push/email/SMS the admin picked. This is a
 * direct, account-specific admin communication (not marketing) — it always
 * sends, regardless of the recipient's push/email/SMS preferences or quiet
 * hours (decided explicitly rather than defaulting to preference-gated like
 * `SendBroadcastToRecipientJob`'s marketing channels), and the SMS channel
 * never touches the customer's own `SmsLedger` balance
 * (`SendAdminSmsNotificationAction`).
 *
 * Guarded against a double-click sending the same "you got bonus credits"
 * notification twice — same `IdempotencyGuard` pattern as the grant
 * actions themselves.
 */
class NotifyCustomerOfCreditGrantAction
{
    public const CHANNEL_PUSH = 'push';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public function __construct(
        private readonly SendPushNotificationAction $sendPush,
        private readonly SendAdminSmsNotificationAction $sendAdminSms,
        private readonly AuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<int, string>  $channels
     * @return array<string, string>|null
     */
    public function handle(AdminUser $admin, User $user, Team $team, array $channels, string $creditType, int $credits, string $note = ''): ?array
    {
        return IdempotencyGuard::once("notify-grant:{$team->id}", 10, fn () => $this->send($admin, $user, $team, $channels, $creditType, $credits, $note));
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<string, string>
     */
    private function send(AdminUser $admin, User $user, Team $team, array $channels, string $creditType, int $credits, string $note): array
    {
        $title = "You've received {$credits} bonus {$creditType} credits";
        $body = $note !== '' ? $note : "Your account was credited with {$credits} bonus {$creditType} credits.";

        $results = [];

        if (in_array(self::CHANNEL_PUSH, $channels, true)) {
            $results[self::CHANNEL_PUSH] = $this->sendPush->handle(
                $user,
                $title,
                $body,
                [],
                Notification::TYPE_ADMIN_NOTE,
                true,
                null,
                null,
                null,
                null,
                true,
            );
        }

        if (in_array(self::CHANNEL_EMAIL, $channels, true)) {
            Mail::to($user->email)->queue(new AdminCreditGrantMail($title, $body));
            Notification::query()->create([
                'user_id' => $user->id,
                'type' => Notification::TYPE_ADMIN_NOTE,
                'title' => $title,
                'body' => $body,
            ]);
            $results[self::CHANNEL_EMAIL] = 'sent';
        }

        if (in_array(self::CHANNEL_SMS, $channels, true)) {
            $results[self::CHANNEL_SMS] = $this->sendAdminSms->handle($user, $body);
        }

        $this->auditLog->handle($admin, 'customer.notify_credit_grant', Team::class, $team->id, null, [
            'channels' => $channels,
            'credit_type' => $creditType,
            'credits' => $credits,
            'note' => $note,
            'results' => $results,
        ]);

        return $results;
    }
}
