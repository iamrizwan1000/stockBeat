<?php

namespace App\Models;

use Database\Factories\AiMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One turn in an AI Assistant conversation (Plan §4.12). `role` is one of
 * `user`/`assistant`/`tool` — `tool` rows record which grounding data an
 * assistant answer actually used (real Action results, never a raw guess),
 * so a conversation's history is a genuine audit trail, not just prose.
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $role
 * @property string|null $content
 * @property array<int, array<string, mixed>>|null $tool_calls
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['conversation_id', 'role', 'content', 'tool_calls'])]
class AiMessage extends Model
{
    /** @use HasFactory<AiMessageFactory> */
    use HasFactory;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
