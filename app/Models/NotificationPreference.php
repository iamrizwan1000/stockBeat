<?php

namespace App\Models;

use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Personal (per-user, not per-team) notification settings for Plan §4.8 —
 * distinct from a rule's own `controls.quiet_hours`/per-channel mute
 * (§4.4/§8.4), which govern whether a *rule* fires at all. This is a
 * per-recipient delivery gate checked after a rule has already fired:
 * "would fire the push, but this person has push off / is in their own
 * quiet hours right now."
 *
 * @property int $id
 * @property int $user_id
 * @property bool $push_enabled
 * @property bool $email_enabled
 * @property bool $sms_enabled
 * @property string|null $quiet_hours_start
 * @property string|null $quiet_hours_end
 * @property string|null $quiet_hours_timezone
 * @property string $sound
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'push_enabled', 'email_enabled', 'sms_enabled', 'quiet_hours_start', 'quiet_hours_end', 'quiet_hours_timezone', 'sound'])]
class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    /**
     * Mirrors the migration's column defaults so `firstOrNew()` on a user
     * with no saved row yet still reports "everything on, no quiet hours"
     * instead of nulls — Eloquent only applies DB defaults on INSERT.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'push_enabled' => true,
        'email_enabled' => true,
        'sms_enabled' => true,
        'sound' => 'default',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'push_enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isWithinQuietHours(): bool
    {
        if (empty($this->quiet_hours_start) || empty($this->quiet_hours_end)) {
            return false;
        }

        $timezone = $this->quiet_hours_timezone ?? $this->user->timezone ?? 'UTC';
        $now = now()->setTimezone($timezone)->format('H:i');
        [$start, $end] = [$this->quiet_hours_start, $this->quiet_hours_end];

        return $start <= $end
            ? $now >= $start && $now < $end
            : $now >= $start || $now < $end;
    }
}
