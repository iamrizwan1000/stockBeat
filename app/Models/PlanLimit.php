<?php

namespace App\Models;

use Database\Factories\PlanLimitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $plan_id
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['plan_id', 'key', 'value', 'updated_by'])]
class PlanLimit extends Model
{
    /** @use HasFactory<PlanLimitFactory> */
    use HasFactory;

    public const MAX_STORES = 'max_stores';

    public const MAX_RULES = 'max_rules';

    public const SMS_MONTHLY = 'sms_monthly';

    public const EMAIL_MONTHLY = 'email_monthly';

    public const HISTORY_DAYS = 'history_days';

    public const TEAM_SEATS = 'team_seats';

    public const TRIAL_DAYS = 'trial_days';

    public const INBOX_ENABLED = 'inbox_enabled';

    public const ANALYTICS_LEVEL = 'analytics_level';

    public const WIDGETS_ENABLED = 'widgets_enabled';

    /**
     * Gates the two "derived/anomaly" rule triggers (order_spike,
     * refund_spike — see `Rule::advancedTriggers()`) to Premium. The other
     * two originally-considered-advanced triggers (low_stock,
     * negative_review) are deliberately NOT behind this flag — they read as
     * basic seller hygiene rather than a luxury add-on, so they're
     * available from Starter up like every other trigger.
     */
    public const ADVANCED_TRIGGERS_ENABLED = 'advanced_triggers_enabled';

    /**
     * AI Assistant gates (Plan §4.12/§5). `AI_QUESTIONS_MONTHLY` of `null`
     * means unlimited; `0` (Free) means the Data Copilot is fully locked —
     * App Help still works regardless, since it isn't gated by this limit.
     */
    public const AI_ENABLED = 'ai_enabled';

    public const AI_QUESTIONS_MONTHLY = 'ai_questions_monthly';

    public const AI_RULE_BUILDER_ENABLED = 'ai_rule_builder_enabled';

    public const AI_PROACTIVE_INSIGHTS_ENABLED = 'ai_proactive_insights_enabled';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
