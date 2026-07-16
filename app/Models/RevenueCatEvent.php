<?php

namespace App\Models;

use Database\Factories\RevenueCatEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Idempotency log for inbound RevenueCat webhook deliveries (Plan §6.1).
 * RevenueCat can redeliver an event on a retry — a `subscriptions` update is
 * naturally idempotent (each event sets absolute state), but an SMS top-up
 * credit grant is NOT, so every event's own unique id is logged here before
 * any side effect runs (`ProcessRevenueCatEventAction`).
 *
 * @property int $id
 * @property string $event_id
 * @property string $event_type
 * @property Carbon $processed_at
 */
#[Fillable(['event_id', 'event_type', 'processed_at'])]
class RevenueCatEvent extends Model
{
    /** @use HasFactory<RevenueCatEventFactory> */
    use HasFactory;

    protected $table = 'revenuecat_events';

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
