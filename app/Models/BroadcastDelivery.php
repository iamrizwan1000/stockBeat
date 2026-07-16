<?php

namespace App\Models;

use Database\Factories\BroadcastDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per (broadcast, recipient, channel) send attempt — the real,
 * append-only delivery report backing `Broadcast` (see that model's
 * docblock for why this exists instead of a mutable JSON counter).
 *
 * @property int $id
 * @property int $broadcast_id
 * @property int $user_id
 * @property string $channel
 * @property string $status
 * @property Carbon|null $created_at
 */
#[Fillable(['broadcast_id', 'user_id', 'channel', 'status'])]
class BroadcastDelivery extends Model
{
    /** @use HasFactory<BroadcastDeliveryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED_NO_CONSENT = 'skipped_no_consent';

    public const STATUS_SKIPPED_MUTED = 'skipped_muted';

    public const STATUS_SKIPPED_QUIET_HOURS = 'skipped_quiet_hours';

    public const STATUS_SKIPPED_NO_DEVICES = 'skipped_no_devices';

    /**
     * @return BelongsTo<Broadcast, $this>
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
