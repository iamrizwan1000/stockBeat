<?php

namespace App\Actions\Billing;

use App\Models\QuotaWarningNotification;
use App\Models\Team;

/**
 * Turns `GetUsageSummaryAction`'s pull-based `quota_warning` flag into a
 * real, once-per-calendar-month-per-channel push notification (Plan §5.1).
 * Checks all three channels independently — a team could cross 80% on SMS
 * and AI questions in the same run and gets notified about both.
 */
class CheckQuotaWarningsAction
{
    private const CHANNEL_KEYS = [
        QuotaWarningNotification::CHANNEL_SMS => 'sms',
        QuotaWarningNotification::CHANNEL_AI_QUESTIONS => 'ai_questions',
        QuotaWarningNotification::CHANNEL_EMAILS => 'emails',
    ];

    public function __construct(
        private readonly GetUsageSummaryAction $getUsageSummary,
        private readonly SendQuotaWarningNotificationAction $sendWarning,
    ) {}

    /**
     * @return int number of warnings actually sent for this team (0-3)
     */
    public function handle(Team $team): int
    {
        $usage = $this->getUsageSummary->handle($team);
        $sent = 0;

        foreach (self::CHANNEL_KEYS as $channel => $usageKey) {
            $channelUsage = $usage[$usageKey];

            if (! $channelUsage['quota_warning']) {
                continue;
            }

            if (QuotaWarningNotification::alreadySentThisMonth($team->id, $channel)) {
                continue;
            }

            $this->sendWarning->handle($team, $channel, $channelUsage['pct_used']);
            $sent++;
        }

        return $sent;
    }
}
