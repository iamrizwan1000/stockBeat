<?php

namespace App\Actions\Teams;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Mail\TeamInviteMail;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invites a member by email (Plan §4.7), enforcing the plan's `team_seats`
 * limit — counting active members AND outstanding pending invites, since a
 * pending invite is a reserved seat the moment it's sent.
 */
class InviteTeamMemberAction
{
    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * @param  array<int, string>|null  $storeVisibility
     */
    public function handle(Team $team, User $invitedBy, string $email, string $role, ?array $storeVisibility = null): TeamInvite
    {
        if ($team->members()->whereHas('user', fn ($q) => $q->where('email', $email))->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This person is already on your team.',
            ]);
        }

        if (TeamInvite::query()->where('team_id', $team->id)->where('email', $email)->where('status', TeamInvite::STATUS_PENDING)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'An invite is already pending for this email.',
            ]);
        }

        $seats = $this->resolveEntitlements->handle($team)['limits']['team_seats'] ?? null;

        if ($seats !== null) {
            $usedSeats = $team->members()->count()
                + TeamInvite::query()->where('team_id', $team->id)->where('status', TeamInvite::STATUS_PENDING)->count();

            if ($usedSeats >= $seats) {
                throw ValidationException::withMessages([
                    'email' => "You've reached your plan's team seat limit ({$seats}). Upgrade to invite more members.",
                ]);
            }
        }

        $invite = TeamInvite::query()->create([
            'team_id' => $team->id,
            'email' => $email,
            'role' => $role,
            'store_visibility' => $storeVisibility,
            'invited_by_user_id' => $invitedBy->id,
            'token' => Str::random(40),
            'status' => TeamInvite::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($email)->queue(new TeamInviteMail(
            teamName: $team->name,
            inviterName: $invitedBy->name,
            role: $role,
        ));

        return $invite;
    }
}
