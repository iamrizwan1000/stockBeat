<?php

namespace App\Models;

use Database\Factories\AiConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A team-scoped AI Assistant chat thread (Plan §4.12). `title` is derived
 * from the first question once one exists — never required up front.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property string|null $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'user_id', 'title'])]
class AiConversation extends Model
{
    /** @use HasFactory<AiConversationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AiMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id');
    }
}
