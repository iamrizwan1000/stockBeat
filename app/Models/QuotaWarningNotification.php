<?php

namespace App\Models;

use Database\Factories\QuotaWarningNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $channel
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'channel'])]
class QuotaWarningNotification extends Model
{
    /** @use HasFactory<QuotaWarningNotificationFactory> */
    use HasFactory;

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_AI_QUESTIONS = 'ai_questions';

    public const CHANNEL_EMAILS = 'emails';

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public static function alreadySentThisMonth(int $teamId, string $channel): bool
    {
        return static::query()
            ->where('team_id', $teamId)
            ->where('channel', $channel)
            ->where('created_at', '>=', now()->startOfMonth())
            ->exists();
    }
}
