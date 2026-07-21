<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $connection_id
 * @property string $platform
 * @property string $external_id
 * @property string $order_number
 * @property string $status
 * @property string $fulfillment_status
 * @property string $payment_status
 * @property string $currency
 * @property float $total
 * @property float|null $discount_amount
 * @property float|null $tax
 * @property float|null $total_base_currency
 * @property string|null $customer_name
 * @property string|null $customer_email
 * @property string|null $buyer_username
 * @property array<string, mixed>|null $shipping_address
 * @property Carbon $placed_at
 * @property Carbon|null $ship_by_at
 * @property string|null $tracking_number
 * @property string|null $carrier
 * @property Carbon|null $check_at
 * @property array<int, string>|null $tags
 * @property array<string, mixed>|null $raw
 * @property bool $is_test
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'team_id', 'connection_id', 'platform', 'external_id', 'order_number',
    'status', 'fulfillment_status', 'payment_status', 'currency', 'total',
    'discount_amount', 'tax',
    'total_base_currency', 'customer_name', 'customer_email', 'buyer_username', 'shipping_address',
    'placed_at', 'ship_by_at', 'tracking_number', 'carrier', 'check_at', 'tags',
    'raw', 'is_test', 'snoozed_until',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_UNFULFILLED = 'unfulfilled';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_CANCELLED = 'cancelled';

    public const FULFILLMENT_UNFULFILLED = 'unfulfilled';

    public const FULFILLMENT_PARTIAL = 'partial';

    public const FULFILLMENT_FULFILLED = 'fulfilled';

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_PARTIALLY_REFUNDED = 'partially_refunded';

    public const PAYMENT_REFUNDED = 'refunded';

    public const PAYMENT_FAILED = 'failed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipping_address' => 'encrypted:array',
            'tags' => 'array',
            'raw' => 'array',
            'is_test' => 'boolean',
            'placed_at' => 'datetime',
            'ship_by_at' => 'datetime',
            'check_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'total' => 'float',
            'discount_amount' => 'float',
            'tax' => 'float',
            'total_base_currency' => 'float',
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

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    /**
     * @return HasMany<OrderNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class);
    }
}
