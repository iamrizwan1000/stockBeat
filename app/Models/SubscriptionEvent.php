<?php

namespace App\Models;

use Database\Factories\SubscriptionEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A team's RevenueCat subscription/event history (Plan §8.7.2 — "subscription
 * timeline (every RevenueCat event)"), appended-only from
 * `ProcessRevenueCatEventAction`. This is deliberately additive to that
 * action's existing crediting/entitlement logic, not a replacement for
 * `revenuecat_events` (which stays the redelivery-idempotency log keyed by
 * `event_id`) or `subscriptions.raw` (which stays "latest event only," used
 * for entitlement resolution). This table is the only place a team's full
 * event *history* is kept.
 *
 * @property int $id
 * @property int $team_id
 * @property string $event_type
 * @property float|null $price
 * @property string|null $currency
 * @property array<string, mixed> $raw_payload
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'event_type', 'price', 'currency', 'raw_payload', 'occurred_at'])]
class SubscriptionEvent extends Model
{
    /** @use HasFactory<SubscriptionEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'raw_payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
