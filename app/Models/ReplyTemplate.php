<?php

namespace App\Models;

use Database\Factories\ReplyTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Saved reply template with variables (Plan §4.5): `{customer_name}`,
 * `{order_number}`, `{tracking}`.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $body_with_variables
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'name', 'body_with_variables'])]
class ReplyTemplate extends Model
{
    /** @use HasFactory<ReplyTemplateFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
