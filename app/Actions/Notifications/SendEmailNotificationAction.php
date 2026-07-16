<?php

namespace App\Actions\Notifications;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Mail\RuleNotificationMail;
use App\Models\Notification;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a real email (Plan §8.2, existing Mail infra) and enforces the
 * plan's `email_monthly` quota (§5.1) — counted across every member of the
 * team, not just the recipient, since the quota is team-scoped.
 */
class SendEmailNotificationAction
{
    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    public function handle(Team $team, User $recipient, string $title, string $body): string
    {
        $emailMonthlyLimit = $this->resolveEntitlements->handle($team)['limits']['email_monthly'] ?? null;

        if ($emailMonthlyLimit !== null && $this->sentThisMonth($team) >= $emailMonthlyLimit) {
            return 'quota_exceeded';
        }

        Notification::query()->create([
            'user_id' => $recipient->id,
            'type' => Notification::TYPE_RULE_EMAIL,
            'title' => $title,
            'body' => $body,
        ]);

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

    private function sentThisMonth(Team $team): int
    {
        $memberUserIds = $team->members()->pluck('user_id');

        return Notification::query()
            ->whereIn('user_id', $memberUserIds)
            ->where('type', Notification::TYPE_RULE_EMAIL)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
    }
}
