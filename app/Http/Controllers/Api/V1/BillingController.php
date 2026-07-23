<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ResolveFullEntitlementsAction;
use App\Actions\Billing\SyncRevenueCatSubscriberAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SyncBillingRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Billing
 *
 * Entitlements are also available embedded in `GET /me` — these are dedicated, lighter-weight
 * endpoints for after a purchase completes, without re-fetching the rest of `/me`'s payload.
 */
class BillingController extends Controller
{
    /**
     * Get current entitlements.
     *
     * Same `entitlements` shape as `GET /me`. The backend is the single source of truth
     * (Plan §6.1) — the app should check its RevenueCat SDK locally for an instant unlock
     * right after a purchase, then call this (or `POST /billing/sync`) to reconcile.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "plan": "pro",
     *     "limits": { "max_stores": 10, "sms_monthly": 100 },
     *     "subscription_status": "active",
     *     "trial_ends_at": null,
     *     "sms_balance": 42,
     *     "ai_questions_remaining": 148,
     *     "emails_remaining": 660
     *   }
     * }
     * @response 422 scenario="profile setup not complete" {
     *   "success": false,
     *   "message": "Complete profile setup first.",
     *   "errors": null
     * }
     */
    public function entitlements(Request $request, ResolveFullEntitlementsAction $resolveEntitlements): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup first.', status: 422);
        }

        return ApiResponse::success($resolveEntitlements->handle($team));
    }

    /**
     * Sync with RevenueCat and get fresh entitlements.
     *
     * Call this after a purchase completes locally (StoreKit/Play Billing via the RevenueCat
     * SDK) or when the merchant taps "Restore Purchases" — links this device's RevenueCat
     * identity to the team's subscription and pulls the subscriber's current state directly
     * from RevenueCat rather than waiting for a webhook, which is the only reliable way to
     * support restore on a new device (a restore doesn't always fire a webhook). Fails open:
     * if RevenueCat itself is unreachable, existing entitlements are returned unchanged rather
     * than erroring (Plan §17.5 — never lock out a paying user over a RevenueCat outage).
     *
     * Only reconciles the renewing subscription, never SMS/AI top-ups — Apple/Google's own
     * restore-purchases rules don't cover consumables, so those stay webhook-only.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "plan": "pro",
     *     "limits": { "max_stores": 10, "sms_monthly": 100 },
     *     "subscription_status": "active",
     *     "trial_ends_at": null,
     *     "sms_balance": 42,
     *     "ai_questions_remaining": 148,
     *     "emails_remaining": 660
     *   }
     * }
     * @response 422 scenario="profile setup not complete" {
     *   "success": false,
     *   "message": "Complete profile setup first.",
     *   "errors": null
     * }
     */
    public function sync(SyncBillingRequest $request, SyncRevenueCatSubscriberAction $syncSubscriber, ResolveFullEntitlementsAction $resolveEntitlements): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup first.', status: 422);
        }

        $syncSubscriber->handle($team, $request->string('rc_app_user_id')->toString());

        return ApiResponse::success($resolveEntitlements->handle($team->fresh()));
    }
}
