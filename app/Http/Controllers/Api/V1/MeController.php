<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\GetActiveAiTopupPacksAction;
use App\Actions\Billing\GetActiveSmsTopupPacksAction;
use App\Actions\Billing\ResolveFullEntitlementsAction;
use App\Actions\Content\GetActiveContentBlocksAction;
use App\Actions\FeatureFlags\GetFeatureFlagsForTeamAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
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
     * Combines profile, current team/role, plan entitlements, SMS credit balance,
     * resolved feature flags, the active SMS top-up pack catalog, and active
     * paywall/content copy blocks — the client's single call after launch/login.
     * `needs_profile_setup` is true until `/profile/setup` has been completed.
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
     *     "entitlements": { "plan": "pro", "history_days": 90, "sms_balance": 42, "ai_questions_remaining": 148 },
     *     "feature_flags": { "new_rules_ui": true },
     *     "sms_topup_packs": [ { "key": "sms_100", "name": "100 SMS", "sms_credits": 100, "price_usd": "2.99" } ],
     *     "ai_topup_packs": [ { "key": "ai_50", "name": "50 AI questions", "ai_questions": 50, "price_usd": "4.99" } ],
     *     "content": { "paywall_pro_headline": "..." },
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
     *     "feature_flags": null,
     *     "sms_topup_packs": [],
     *     "ai_topup_packs": [],
     *     "content": {},
     *     "needs_profile_setup": true
     *   }
     * }
     */
    public function show(
        Request $request,
        ResolveFullEntitlementsAction $resolveEntitlements,
        GetFeatureFlagsForTeamAction $getFeatureFlags,
        GetActiveSmsTopupPacksAction $getSmsTopupPacks,
        GetActiveAiTopupPacksAction $getAiTopupPacks,
        GetActiveContentBlocksAction $getContentBlocks,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::success([
                'user' => new UserResource($user),
                'team' => null,
                'entitlements' => null,
                'feature_flags' => null,
                'sms_topup_packs' => $getSmsTopupPacks->handle(),
                'ai_topup_packs' => $getAiTopupPacks->handle(),
                'content' => $getContentBlocks->handle(),
                'needs_profile_setup' => true,
            ]);
        }

        return ApiResponse::success([
            'user' => new UserResource($user),
            'team' => ['id' => $team->id, 'name' => $team->name, 'role' => $user->currentTeamMember()?->role],
            'entitlements' => $resolveEntitlements->handle($team),
            'feature_flags' => $getFeatureFlags->handle($team),
            'sms_topup_packs' => $getSmsTopupPacks->handle(),
            'ai_topup_packs' => $getAiTopupPacks->handle(),
            'content' => $getContentBlocks->handle(),
            'needs_profile_setup' => false,
        ]);
    }
}
