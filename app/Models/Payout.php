<?php

namespace App\Models;

use Database\Factories\PayoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A polled payout event (Plan §4.14) — read-only, purely "what actually hit
 * the bank," never an editable or actionable record. Shopify Payments only
 * in v1 (`ShopifyAdapter::fetchPayouts()`) — no other platform has a single,
 * well-defined payout API to poll (WooCommerce's payouts depend on whatever
 * gateway plugin the merchant uses, not a WooCommerce-native concept).
 *
 * @property int $id
 * @property int $team_id
 * @property int $connection_id
 * @property string $external_id
 * @property float $amount
 * @property string $currency
 * @property string $status
 * @property Carbon|null $arrival_date
 * @property array<string, mixed>|null $raw
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'connection_id', 'external_id', 'amount', 'currency', 'status', 'arrival_date', 'raw'])]
class Payout extends Model
{
    /** @use HasFactory<PayoutFactory> */
    use HasFactory;

    /**
     * These match Shopify's own Payouts API status vocabulary exactly
     * (`scheduled`/`in_transit`/`paid`/`failed`/`canceled`) rather than an
     * invented one — `status` is stored as whatever the platform itself
     * reports, since this is the only platform feeding this table today.
     */
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'arrival_date' => 'datetime',
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
     * @return BelongsTo<StoreConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(StoreConnection::class, 'connection_id');
    }
}
