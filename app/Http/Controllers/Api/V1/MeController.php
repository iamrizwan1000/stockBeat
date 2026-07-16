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

/**
 * @group Me
 */
class MeController extends Controller
{
    /**
     * Get the current user.
     *
     * Combines profile, current team/role, plan entitlements, and SMS credit balance —
     * the client's single call after launch/login. `needs_profile_setup` is true until
     * `/profile/setup` has been completed.
     *
     * @response 200 scenario="profile setup complete" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "user": {
     *       "id": 1,
     *       "name": "Jamie Rivera",
     *       "email": "jamie@example.com",
     *       "business_name": "Rivera Vintage Co",
     *       "base_currency": "AUD",
     *       "timezone": "Australia/Sydney",
     *       "sells_on": ["woocommerce"]
     *     },
     *     "team": { "id": 1, "name": "Rivera Vintage Co", "role": "owner" },
     *     "entitlements": { "plan": "pro", "history_days": 90, "sms_balance": 42 },
     *     "needs_profile_setup": false
     *   }
     * }
     * @response 200 scenario="profile setup pending" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "user": { "id": 1, "name": "Jamie Rivera", "email": "jamie@example.com", "business_name": null, "base_currency": null, "timezone": null, "sells_on": null },
     *     "team": null,
     *     "entitlements": null,
     *     "needs_profile_setup": true
     *   }
     * }
     */
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
