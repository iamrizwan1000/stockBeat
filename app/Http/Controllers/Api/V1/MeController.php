<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\SmsLedger;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request, ResolveEntitlementsAction $resolveEntitlements): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::success([
                'user' => new UserResource($user),
                'team' => null,
                'entitlements' => null,
                'needs_profile_setup' => true,
            ]);
        }

        $entitlements = $resolveEntitlements->handle($team);

        return ApiResponse::success([
            'user' => new UserResource($user),
            'team' => ['id' => $team->id, 'name' => $team->name, 'role' => $user->currentTeamMember()?->role],
            'entitlements' => [
                ...$entitlements,
                'sms_balance' => SmsLedger::currentBalance($team->id),
            ],
            'needs_profile_setup' => false,
        ]);
    }
}
