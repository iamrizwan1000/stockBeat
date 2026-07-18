<?php

namespace App\Models;

use Database\Factories\InboxMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $thread_id
 * @property string $direction
 * @property string $body
 * @property int|null $sent_by
 * @property string|null $external_id
 * @property string $status
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 */
#[Fillable(['thread_id', 'direction', 'body', 'sent_by', 'external_id', 'status', 'failure_reason'])]
class InboxMessage extends Model
{
    /** @use HasFactory<InboxMessageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    /**
     * @return BelongsTo<InboxThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(InboxThread::class, 'thread_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
