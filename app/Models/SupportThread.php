<?php

namespace App\Models;

use Database\Factories\SupportThreadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Live support chat thread (Plan §4.9/§8.7.6). One thread per user — the
 * "Help" entry in Settings always resumes the same conversation rather than
 * starting a new one, matching how a real support inbox behaves.
 *
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property int|null $assigned_admin_id
 * @property string $priority
 * @property Carbon|null $last_message_at
 * @property int|null $csat
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'status', 'assigned_admin_id', 'priority', 'last_message_at', 'csat', 'resolved_at'])]
class SupportThread extends Model
{
    /** @use HasFactory<SupportThreadFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_AWAITING_USER = 'awaiting_user';

    public const STATUS_RESOLVED = 'resolved';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
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
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    /**
     * @return HasMany<SupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'thread_id');
    }
}
