<?php

namespace App\Models;

use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A polled product review (Plan §4.4's `negative_review` trigger).
 * `replied_at` (added 2026-07-27) is the one piece of state we keep on our
 * side — everything else is read-only, replying itself is delegated live to
 * the platform adapter each time (§4.15).
 *
 * @property int $id
 * @property int $team_id
 * @property int $connection_id
 * @property string $external_id
 * @property string|null $product_title
 * @property int $rating
 * @property string|null $reviewer_name
 * @property string|null $content
 * @property Carbon|null $replied_at
 * @property Carbon $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'connection_id', 'external_id', 'product_title', 'rating', 'reviewer_name', 'content', 'replied_at', 'reviewed_at'])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'replied_at' => 'datetime',
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
}
