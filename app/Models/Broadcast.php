<?php

namespace App\Models;

use Database\Factories\BroadcastFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Admin → user messaging campaign (Plan §8.7.5): compose to all/a segment/a
 * single user, across push/email/banner channels. Real delivery outcomes
 * (sent/failed/skipped) live in `broadcast_deliveries`, not a mutable
 * `stats` counter here — `stats` only ever holds a point-in-time dispatch
 * summary (recipient count found at send time), never fabricated
 * delivered/opened figures (no receipt/open-pixel infra exists yet).
 *
 * @property int $id
 * @property string $audience_type
 * @property int|null $segment_id
 * @property int|null $user_id
 * @property array<int, string> $channels
 * @property string $title
 * @property string $body
 * @property array<string, mixed>|null $template_vars
 * @property string $status
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $sent_at
 * @property array<string, mixed>|null $stats
 * @property int $created_by
 * @property int|null $approved_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['audience_type', 'segment_id', 'user_id', 'channels', 'title', 'body', 'template_vars', 'status', 'scheduled_at', 'sent_at', 'stats', 'created_by', 'approved_by'])]
class Broadcast extends Model
{
    /** @use HasFactory<BroadcastFactory> */
    use HasFactory;

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_SEGMENT = 'segment';

    public const AUDIENCE_USER = 'user';

    public const CHANNEL_PUSH = 'push';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_BANNER = 'banner';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'template_vars' => 'array',
            'stats' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Segment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }

    /**
     * @return HasMany<BroadcastDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(BroadcastDelivery::class);
    }
}
