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
