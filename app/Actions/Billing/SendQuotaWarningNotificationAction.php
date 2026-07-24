<?php

namespace App\Actions\Billing;

use App\Actions\Notifications\SendPushNotificationAction;
use App\Models\Notification;
use App\Models\QuotaWarningNotification;
use App\Models\Team;

/**
 * The 80%-quota push Plan §5.1 calls for ("quota consumption shown in
 * Settings with an upsell at 80%") but nothing previously sent — sent to
 * the team owner, same recipient convention as `SendTrialReminderNotificationAction`
 * (the only person who currently sees billing/usage state at all). Callers
 * are responsible for checking `GetUsageSummaryAction`'s `quota_warning`
 * flag and `QuotaWarningNotification::alreadySentThisMonth()` before
 * calling this — this action always sends and always records, it doesn't
 * re-check either condition itself (see `CheckQuotaWarningsAction`).
 */
class SendQuotaWarningNotificationAction
{
    private const CHANNEL_LABELS = [
        QuotaWarningNotification::CHANNEL_SMS => 'SMS credits',
        QuotaWarningNotification::CHANNEL_AI_QUESTIONS => 'AI questions',
        QuotaWarningNotification::CHANNEL_EMAILS => 'email alerts',
    ];

    public function __construct(
        private readonly SendPushNotificationAction $sendPush,
    ) {}

    public function handle(Team $team, string $channel, float $pctUsed): void
    {
        $label = self::CHANNEL_LABELS[$channel] ?? $channel;
        $pctRounded = (int) round($pctUsed);

        $title = "You're running low on {$label}";
        // Email has no top-up product (settings-api-reference.md) — the only
        // lever for it is a plan upgrade, so its copy omits "buy more".
        $body = $channel === QuotaWarningNotification::CHANNEL_EMAILS
            ? "You've used {$pctRounded}% of this month's {$label}. Upgrade your plan for a higher monthly limit."
            : "You've used {$pctRounded}% of this month's {$label}. Buy more or upgrade your plan to keep going.";

        $this->sendPush->handle(
            $team->owner,
            $title,
            $body,
            ['channel' => $channel, 'pct_used' => (string) $pctUsed],
            Notification::TYPE_QUOTA_WARNING,
        );

        QuotaWarningNotification::query()->create([
            'team_id' => $team->id,
            'channel' => $channel,
        ]);
    }
}
