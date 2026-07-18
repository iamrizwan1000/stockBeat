<?php

namespace App\Models;

use Database\Factories\PromoCampaignRedemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Records that a team redeemed a `server_comp` `PromoCampaign` — written by
 * `ApplyServerCompToSegmentAction` (Plan §8.7.4), read by
 * `ComputeCampaignStatsAction` to turn "this campaign was applied" into real
 * redemptions/conversion/revenue-impact numbers. One row per (campaign,
 * team) — see the unique index in the migration — so re-applying a campaign
 * to a team that already redeemed it just bumps `redeemed_at` instead of
 * inflating the redemption count.
 *
 * @property int $id
 * @property int $promo_campaign_id
 * @property int $team_id
 * @property Carbon $redeemed_at
 * @property int|null $subscription_event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['promo_campaign_id', 'team_id', 'redeemed_at', 'subscription_event_id'])]
class PromoCampaignRedemption extends Model
{
    /** @use HasFactory<PromoCampaignRedemptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PromoCampaign, $this>
     */
    public function promoCampaign(): BelongsTo
    {
        return $this->belongsTo(PromoCampaign::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<SubscriptionEvent, $this>
     */
    public function subscriptionEvent(): BelongsTo
    {
        return $this->belongsTo(SubscriptionEvent::class);
    }
}
