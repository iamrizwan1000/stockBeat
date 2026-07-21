<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-user "I've closed this" record for an in-app announcement banner
 * (`notifications-api-reference.md`'s "no dismiss endpoint" gap, closed
 * 2026-07-22). Deliberately its own table rather than a `dismissed_by`
 * column on `announcements` — an announcement is shared across every
 * targeted user, so dismissal has to be per-(user, announcement), not a
 * single flag on the announcement itself.
 *
 * @property int $id
 * @property int $user_id
 * @property int $announcement_id
 * @property Carbon $dismissed_at
 */
#[Fillable(['user_id', 'announcement_id', 'dismissed_at'])]
class AnnouncementDismissal extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
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
     * @return BelongsTo<Announcement, $this>
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
