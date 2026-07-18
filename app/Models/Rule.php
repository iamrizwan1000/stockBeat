<?php

namespace App\Models;

use Database\Factories\RuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $trigger
 * @property array{all?: array<int, array<string, mixed>>, any?: array<int, array<string, mixed>>}|null $conditions
 * @property array<int, array<string, mixed>> $actions
 * @property string|null $sound
 * @property array{quiet_hours?: array<string, string>, cooldown_minutes?: int, threshold_hours?: int, digest_frequency?: 'daily'|'weekly', digest_time?: string, digest_day_of_week?: int, spike_count?: int, spike_window_minutes?: int, low_stock_threshold?: int, negative_review_max_rating?: int}|null $controls
 * @property bool $enabled
 * @property Carbon|null $auto_disabled_at
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'name', 'trigger', 'conditions', 'actions', 'sound', 'controls', 'enabled', 'auto_disabled_at', 'created_by'])]
class Rule extends Model
{
    /** @use HasFactory<RuleFactory> */
    use HasFactory;

    public const TRIGGER_NEW_ORDER = 'new_order';

    public const TRIGGER_HIGH_VALUE_ORDER = 'high_value_order';

    public const TRIGGER_UNFULFILLED_AFTER_X = 'unfulfilled_after_x';

    public const TRIGGER_SHIP_BY_DEADLINE = 'ship_by_deadline';

    public const TRIGGER_REFUND_REQUESTED = 'refund_requested';

    public const TRIGGER_ORDER_CANCELLED = 'order_cancelled';

    public const TRIGGER_PAYMENT_FAILED = 'payment_failed';

    public const TRIGGER_NEGATIVE_REVIEW = 'negative_review';

    public const TRIGGER_LOW_STOCK = 'low_stock';

    public const TRIGGER_ORDER_SPIKE = 'order_spike';

    public const TRIGGER_REFUND_SPIKE = 'refund_spike';

    public const TRIGGER_DIGEST = 'digest';

    public const SOUND_DEFAULT = 'default';

    public const SOUND_CHA_CHING = 'cha_ching';

    public const SOUND_ALERT = 'alert';

    public const SOUND_CHIME = 'chime';

    /**
     * The fixed catalog of bundled push-notification sound keys a rule may
     * select (Plan §4.4: "custom sound option — the 'cha-ching'") — not
     * free text, since these map to sound files the mobile app actually
     * ships (see `SendPushNotificationAction`'s FCM payload wiring).
     *
     * @return array<int, string>
     */
    public static function sounds(): array
    {
        return [
            self::SOUND_DEFAULT,
            self::SOUND_CHA_CHING,
            self::SOUND_ALERT,
            self::SOUND_CHIME,
        ];
    }

    /**
     * All trigger keys from Plan §4.4 — all 12 have a real evaluation path
     * as of 2026-07-16 (see `RuleEvaluationAction`, `CheckLowStockAction`,
     * `CheckNegativeReviewAction`, `SendRuleDigests`).
     *
     * @return array<int, string>
     */
    public static function triggers(): array
    {
        return [
            self::TRIGGER_NEW_ORDER,
            self::TRIGGER_HIGH_VALUE_ORDER,
            self::TRIGGER_UNFULFILLED_AFTER_X,
            self::TRIGGER_SHIP_BY_DEADLINE,
            self::TRIGGER_REFUND_REQUESTED,
            self::TRIGGER_ORDER_CANCELLED,
            self::TRIGGER_PAYMENT_FAILED,
            self::TRIGGER_NEGATIVE_REVIEW,
            self::TRIGGER_LOW_STOCK,
            self::TRIGGER_ORDER_SPIKE,
            self::TRIGGER_REFUND_SPIKE,
            self::TRIGGER_DIGEST,
        ];
    }

    /**
     * The two "derived/anomaly" triggers reserved for the Premium plan
     * (`plan_limits.advanced_triggers_enabled` — see `PlanSeeder`).
     * `low_stock`/`negative_review` are deliberately not in this list —
     * they're available from Starter up like every other trigger.
     *
     * @return array<int, string>
     */
    public static function advancedTriggers(): array
    {
        return [
            self::TRIGGER_ORDER_SPIKE,
            self::TRIGGER_REFUND_SPIKE,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'actions' => 'array',
            'controls' => 'array',
            'enabled' => 'boolean',
            'auto_disabled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<RuleExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(RuleExecution::class);
    }
}
