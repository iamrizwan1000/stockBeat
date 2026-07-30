<?php

namespace App\Models;

use Database\Factories\ContactThreadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A public "Contact us" submission from an anonymous website visitor —
 * mirrors `SupportThread`'s shape, scaled down for a guest with no `User`
 * account: no `assigned_admin_id`/`priority`/`csat` (those are Support
 * Inbox's own triage concepts), just enough to list, view, and reply to a
 * guest inquiry by email.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $subject
 * @property string $status
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'subject', 'status', 'last_message_at'])]
class ContactThread extends Model
{
    /** @use HasFactory<ContactThreadFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_CLOSED = 'closed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ContactMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ContactMessage::class, 'thread_id');
    }
}
