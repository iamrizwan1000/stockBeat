<?php

namespace App\Actions\Notifications;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;

/**
 * Targets a specific team member rather than the rule's creator (Plan
 * §4.4 "notify specific team member(s)").
 */
class NotifyMemberAction
{
    public function __construct(
        private readonly SendPushNotificationAction $sendPush,
    ) {}

    public function handle(Team $team, int $targetUserId, string $title, string $body, ?string $sound = null): string
    {
        $isMember = TeamMember::query()
            ->where('team_id', $team->id)
            ->where('user_id', $targetUserId)
            ->exists();

        if (! $isMember) {
            return 'not_a_team_member';
        }

        $user = User::query()->find($targetUserId);

        if ($user === null) {
            return 'not_a_team_member';
        }

        return $this->sendPush->handle($user, $title, $body, sound: $sound);
    }
}
