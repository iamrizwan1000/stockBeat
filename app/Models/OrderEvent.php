<?php

namespace App\Models;

use Database\Factories\OrderEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property string $type
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
#[Fillable(['order_id', 'type', 'payload', 'occurred_at'])]
class OrderEvent extends Model
{
    /** @use HasFactory<OrderEventFactory> */
    use HasFactory;

    public const TYPE_CREATED = 'created';

    public const TYPE_UPDATED = 'updated';

    /**
     * Added 2026-07-27 (Plan §4.19, "order timeline") — every quick action
     * now writes one of these, closing a real gap: the table/model/relation
     * all existed already, but every action past ingestion silently wrote
     * nothing, so `GET /orders/{id}` could never show what actually
     * happened to an order beyond "it was created/updated."
     */
    public const TYPE_FULFILLED = 'fulfilled';

    public const TYPE_REFUNDED = 'refunded';

    public const TYPE_CANCELLED = 'cancelled';

    public const TYPE_SNOOZED = 'snoozed';

    public const TYPE_TAGS_UPDATED = 'tags_updated';

    public const TYPE_NOTE_ADDED = 'note_added';

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
