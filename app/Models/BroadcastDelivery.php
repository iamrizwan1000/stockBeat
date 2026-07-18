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
 * @property Carbon|null $opened_at
 * @property Carbon|null $unsubscribed_at
 * @property int|null $notification_id
 * @property Carbon|null $created_at
 */
#[Fillable(['broadcast_id', 'user_id', 'channel', 'status', 'opened_at', 'unsubscribed_at', 'notification_id'])]
class BroadcastDelivery extends Model
{
    /** @use HasFactory<BroadcastDeliveryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED_NO_CONSENT = 'skipped_no_consent';

    public const STATUS_SKIPPED_UNSUBSCRIBED = 'skipped_unsubscribed';

    public const STATUS_SKIPPED_MUTED = 'skipped_muted';

    public const STATUS_SKIPPED_QUIET_HOURS = 'skipped_quiet_hours';

    public const STATUS_SKIPPED_NO_DEVICES = 'skipped_no_devices';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

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

    /**
     * The in-app `Notification` row this delivery fanned out to (push and
     * banner channels only — email has no such row, it has the tracking
     * pixel instead). The recipient marking that notification read is the
     * proxy signal `MarkNotificationsReadAction` uses to stamp `opened_at`
     * here — see that action's docblock for why this is a proxy, not a
     * literal "user tapped the push" event.
     *
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
