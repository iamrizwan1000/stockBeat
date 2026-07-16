<?php

namespace App\Models;

use Database\Factories\InboxThreadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Unified customer inbox thread (Plan §4.5/§9). Scoped to Shopify/Woo
 * order-linked email threading only — eBay/Etsy/Amazon native messaging
 * needs those adapters, which are still stubs.
 *
 * @property int $id
 * @property int $team_id
 * @property int $connection_id
 * @property int|null $order_id
 * @property string $channel
 * @property string|null $external_thread_id
 * @property string|null $customer_name
 * @property string|null $customer_email
 * @property int|null $assigned_to
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 */
#[Fillable(['team_id', 'connection_id', 'order_id', 'channel', 'external_thread_id', 'customer_name', 'customer_email', 'assigned_to', 'last_message_at'])]
class InboxThread extends Model
{
    /** @use HasFactory<InboxThreadFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
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
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<InboxMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(InboxMessage::class, 'thread_id');
    }
}
