<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string|null $provider
 * @property string|null $rc_app_user_id
 * @property string|null $product_id
 * @property string|null $plan_key
 * @property string $status
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $renewed_at
 * @property array<string, mixed>|null $raw
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'provider', 'rc_app_user_id', 'product_id', 'plan_key', 'status', 'trial_ends_at', 'expires_at', 'renewed_at', 'raw'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_GRACE = 'grace';

    public const STATUS_EXPIRED = 'expired';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'expires_at' => 'datetime',
            'renewed_at' => 'datetime',
            'raw' => 'array',
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
     * True while the subscription's billing state should be honored at
     * all — active/grace, or an unexpired trial. Renamed from the old
     * `isCurrentlyPro()` now that there are multiple paid tiers: this
     * answers "is *any* paid entitlement currently in effect," not "which
     * one" — see `effectivePlanKey()` for that.
     */
    public function isEntitled(): bool
    {
        return match ($this->status) {
            self::STATUS_ACTIVE, self::STATUS_GRACE => true,
            self::STATUS_TRIAL => $this->trial_ends_at !== null && $this->trial_ends_at->isFuture(),
            default => false,
        };
    }

    /**
     * The `Plan::key` this team is actually entitled to right now. Falls
     * back to Free both when there's no entitlement in effect and when
     * `plan_key` was never set (shouldn't happen once every activation
     * path sets it, but Free is the only safe default either way).
     */
    public function effectivePlanKey(): string
    {
        return $this->isEntitled() ? ($this->plan_key ?? Plan::FREE) : Plan::FREE;
    }
}
