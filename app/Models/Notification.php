<?php

namespace App\Models;

use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $title
 * @property string $body
 * @property array<string, mixed>|null $data
 * @property string $priority
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'type', 'title', 'body', 'data', 'priority', 'read_at'])]
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    public const TYPE_RULE_PUSH = 'rule_push';

    public const TYPE_RULE_EMAIL = 'rule_email';

    public const TYPE_RULE_SMS = 'rule_sms';

    public const TYPE_DIGEST = 'digest';

    public const TYPE_ADMIN_BROADCAST = 'admin_broadcast';

    /**
     * A direct, one-to-one admin communication about a specific account
     * action (e.g. a bonus credit grant) — distinct from `TYPE_ADMIN_BROADCAST`
     * (a segment/all-user marketing send) and, critically, from
     * `TYPE_RULE_EMAIL`/`TYPE_RULE_SMS`/`TYPE_RULE_PUSH`, so it never counts
     * against the team's own `email_monthly` quota (`emailsSentThisMonth()`
     * below only ever counts `TYPE_RULE_EMAIL`) or debits any ledger.
     */
    public const TYPE_ADMIN_NOTE = 'admin_note';

    public const TYPE_SUPPORT_REPLY = 'support_reply';

    public const TYPE_TRIAL_REMINDER = 'trial_reminder';

    public const TYPE_INBOX_MESSAGE = 'inbox_message';

    public const TYPE_QUOTA_WARNING = 'quota_warning';

    /**
     * The three subscription lifecycle notices (added 2026-07-31): a purchase
     * or tier change landing, a failed payment putting the subscription into
     * its grace period, and a subscription actually lapsing. Transactional
     * account notices about the seller's *own* billing state — like
     * `TYPE_ADMIN_NOTE` and `TYPE_TRIAL_REMINDER`, and unlike
     * `TYPE_RULE_EMAIL`, they never count against the team's `email_monthly`
     * quota (`emailsSentThisMonth()` below only ever counts
     * `TYPE_RULE_EMAIL`) — a seller shouldn't spend their own alert
     * allowance being told their card was declined.
     *
     * Deliberately no `renewal` type: a renewal fires every billing cycle,
     * so notifying on it would be recurring spam rather than information
     * (see `ProcessRevenueCatEventAction`'s dispatch block).
     */
    public const TYPE_SUBSCRIPTION_STARTED = 'subscription_started';

    public const TYPE_SUBSCRIPTION_PAYMENT_ISSUE = 'subscription_payment_issue';

    public const TYPE_SUBSCRIPTION_EXPIRED = 'subscription_expired';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL = 'critical';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Counts real `rule_email` sends across every member of the team this
     * calendar month — the same query `SendEmailNotificationAction` already
     * used privately to enforce `plan_limits.email_monthly` (Plan §5.1),
     * pulled out here so `ResolveFullEntitlementsAction` can expose the
     * count too (`emails_remaining`, mirroring `AiUsageLedger`'s
     * `questionsUsedThisMonth`) without a second, drifting copy of the
     * query.
     */
    public static function emailsSentThisMonth(Team $team): int
    {
        $memberUserIds = $team->members()->pluck('user_id');

        return static::query()
            ->whereIn('user_id', $memberUserIds)
            ->where('type', self::TYPE_RULE_EMAIL)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * Daily `rule_email` counts across every team member for the last
     * `$days` days (today inclusive), keyed by `Y-m-d` — same shape/purpose
     * as `SmsLedger::dailySendCounts()`/`AiUsageLedger::dailyQuestionCounts()`,
     * kept here since email usage has no dedicated ledger table of its own.
     *
     * @return array<string, int>
     */
    public static function dailyEmailCounts(Team $team, int $days): array
    {
        $memberUserIds = $team->members()->pluck('user_id');

        return static::query()
            ->whereIn('user_id', $memberUserIds)
            ->where('type', self::TYPE_RULE_EMAIL)
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->groupBy('day')
            ->pluck('count', 'day')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
