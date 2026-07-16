<?php

namespace App\Models;

use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * In-app banner content (Plan §8.7.5/§10 `GET /config`), consumed by the
 * mobile app via `GET /api/v1/announcements`. `audience` uses the same
 * filter shape as `Segment::filters` — null means everyone.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property array<string, mixed>|null $audience
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $dismissible
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'body', 'audience', 'starts_at', 'ends_at', 'dismissible', 'created_by'])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'dismissible' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        $now = now();

        return ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->ends_at === null || $this->ends_at->gte($now));
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
