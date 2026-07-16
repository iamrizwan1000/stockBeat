<?php

namespace App\Models;

use Database\Factories\SupportMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $thread_id
 * @property string $direction
 * @property int|null $admin_id
 * @property string $body
 * @property array<int, mixed>|null $attachments
 * @property array<string, mixed>|null $delivered_via
 * @property Carbon|null $created_at
 */
#[Fillable(['thread_id', 'direction', 'admin_id', 'body', 'attachments', 'delivered_via'])]
class SupportMessage extends Model
{
    /** @use HasFactory<SupportMessageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const DIRECTION_USER = 'user';

    public const DIRECTION_STAFF = 'staff';

    public const DIRECTION_NOTE = 'note';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'delivered_via' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SupportThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(SupportThread::class, 'thread_id');
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }
}
