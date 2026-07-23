<?php

namespace App\Actions\Notifications;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Mail\RuleNotificationMail;
use App\Models\Notification;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a real email (Plan §8.2, existing Mail infra) and enforces the
 * plan's `email_monthly` quota (§5.1) — counted across every member of the
 * team, not just the recipient, since the quota is team-scoped.
 *
 * `$connection`, when given, gates the send on that store's
 * `notifications_muted` flag (§4.8 follow-up) — same optional,
 * defaults-to-null convention as `SendPushNotificationAction`. Also stamps
 * `data.platform` (added 2026-07-24) — same "where did this come from"
 * convention `SendPushNotificationAction` uses. `$extraData` (e.g. `trigger`)
 * merges in alongside it.
 */
class SendEmailNotificationAction
{
    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $extraData
     */
    public function handle(Team $team, User $recipient, string $title, string $body, ?StoreConnection $connection = null, array $extraData = []): string
    {
        $emailMonthlyLimit = $this->resolveEntitlements->handle($team)['limits']['email_monthly'] ?? null;

        if ($emailMonthlyLimit !== null && Notification::emailsSentThisMonth($team) >= $emailMonthlyLimit) {
            return 'quota_exceeded';
        }

        $data = $connection !== null ? [...$extraData, 'platform' => $connection->platform] : $extraData;

        Notification::query()->create([
            'user_id' => $recipient->id,
            'type' => Notification::TYPE_RULE_EMAIL,
            'title' => $title,
            'body' => $body,
            'data' => $data !== [] ? $data : null,
        ]);

        if ($connection !== null && $connection->notifications_muted) {
            return 'muted_by_store';
        }

        $preference = $recipient->notificationPreference;

        if ($preference !== null && ! $preference->email_enabled) {
            return 'muted_by_preference';
        }

        if ($preference !== null && $preference->isWithinQuietHours()) {
            return 'quiet_hours';
        }

        Mail::to($recipient->email)->queue(new RuleNotificationMail($title, $body));

        return 'sent';
    }
}
