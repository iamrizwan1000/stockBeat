<?php

namespace App\Models;

use Database\Factories\ContactMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message within a `ContactThread` — mirrors `SupportMessage`'s shape
 * (no `updated_at`, same `admin_id`/`direction` convention), but `direction`
 * only ever has two values here: the guest's original message, and an
 * admin's reply. There's no inbound-parse email provider connected in this
 * codebase, so a guest can't reply back into the thread — only submit a new
 * one via the contact form.
 *
 * @property int $id
 * @property int $thread_id
 * @property string $direction
 * @property int|null $admin_id
 * @property string $body
 * @property Carbon|null $created_at
 */
#[Fillable(['thread_id', 'direction', 'admin_id', 'body', 'created_at'])]
class ContactMessage extends Model
{
    /** @use HasFactory<ContactMessageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const DIRECTION_GUEST = 'guest';

    public const DIRECTION_STAFF = 'staff';

    /**
     * @return BelongsTo<ContactThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ContactThread::class, 'thread_id');
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }
}
