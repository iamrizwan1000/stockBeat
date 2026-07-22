<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * End user (e-commerce seller). Authenticates passwordless via email OTP on
 * the mobile API (Plan §4.1) — no password column by design.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $business_name
 * @property string|null $phone
 * @property string $base_currency
 * @property string|null $timezone
 * @property array<int, string>|null $sells_on
 * @property bool $marketing_opt_in
 * @property string|null $signup_ip
 * @property string|null $apple_sub
 * @property string|null $google_sub
 * @property Carbon|null $last_active_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'email', 'business_name', 'phone', 'base_currency', 'timezone', 'sells_on', 'marketing_opt_in'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sells_on' => 'array',
            'marketing_opt_in' => 'boolean',
            'last_active_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return HasOne<Team, $this>
     */
    public function ownedTeam(): HasOne
    {
        return $this->hasOne(Team::class, 'owner_id');
    }

    /**
     * @return HasMany<TeamMember, $this>
     */
    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    /**
     * The team this user operates in on the mobile app. A user belongs to
     * exactly one team (their own, or one they were invited into) — the
     * mobile app has no team-switcher, per Plan §4.7's "very easy to use"
     * mandate, so `RedeemTeamInviteAction` refuses to add a second
     * membership for a user who already has one.
     */
    public function currentTeamMember(): ?TeamMember
    {
        return $this->teamMemberships()->with('team')->first();
    }

    public function currentTeam(): ?Team
    {
        return $this->currentTeamMember()?->team;
    }

    /**
     * @return HasOne<NotificationPreference, $this>
     */
    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }
}
