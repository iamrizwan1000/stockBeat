<?php

namespace App\Models;

use Database\Factories\PaywallHitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per real gate-check rejection (a team was actually blocked by a
 * plan limit) — `limit_key` reuses `PlanLimit`'s own constants rather than
 * duplicating them, same as `Rule`/`Notification` priority elsewhere. This
 * is the "what's the upsell signal" counterpart to `FeatureUsageEvent`
 * ("what's already being used"); together they close the admin dashboard's
 * feature-adoption and paywall-conversion blind spots (both previously
 * untracked).
 */
#[Fillable(['team_id', 'limit_key', 'plan_key', 'occurred_at'])]
class PaywallHit extends Model
{
    /** @use HasFactory<PaywallHitFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public static function log(Team $team, string $limitKey): void
    {
        static::query()->create([
            'team_id' => $team->id,
            'limit_key' => $limitKey,
            'plan_key' => $team->subscription?->effectivePlanKey() ?? Plan::FREE,
            'occurred_at' => now(),
        ]);
    }
}
