<?php

namespace App\Models;

use Database\Factories\SavedOrderFilterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A named, team-shared preset over `ListOrdersAction`'s own filter params
 * (Plan §4.23) — "favorite/saved filters". Free on every plan (§5) since
 * it sits on top of the order feed & filters, which are already free
 * everywhere.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property array<string, mixed> $filters
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'name', 'filters'])]
class SavedOrderFilter extends Model
{
    /** @use HasFactory<SavedOrderFilterFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
