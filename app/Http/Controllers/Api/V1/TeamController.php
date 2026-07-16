<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Teams\InviteTeamMemberAction;
use App\Actions\Teams\UpdateTeamMemberAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\InviteTeamMemberRequest;
use App\Http\Requests\Teams\UpdateTeamMemberRequest;
use App\Http\Resources\TeamInviteResource;
use App\Http\Resources\TeamMemberResource;
use App\Http\Responses\ApiResponse;
use App\Models\TeamInvite;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup first.', status: 422);
        }

        $members = TeamMember::query()->where('team_id', $team->id)->with('user')->get();
        $pendingInvites = TeamInvite::query()
            ->where('team_id', $team->id)
            ->where('status', TeamInvite::STATUS_PENDING)
            ->get();

        return ApiResponse::success([
            'members' => TeamMemberResource::collection($members),
            'pending_invites' => TeamInviteResource::collection($pendingInvites),
        ]);
    }

    public function invite(InviteTeamMemberRequest $request, InviteTeamMemberAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup first.', status: 422);
        }

        $invite = $action->handle(
            team: $team,
            invitedBy: $user,
            email: $request->string('email')->toString(),
            role: $request->string('role')->toString(),
            storeVisibility: $request->input('store_visibility'),
        );

        return ApiResponse::success(['invite' => new TeamInviteResource($invite)], status: 201);
    }

    public function update(UpdateTeamMemberRequest $request, TeamMember $member, UpdateTeamMemberAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($member->team_id !== $user->currentTeam()?->id) {
            abort(404);
        }

        $member = $action->handle($member, $request->validated());

        return ApiResponse::success(['member' => new TeamMemberResource($member->fresh('user'))]);
    }
}
