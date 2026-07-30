<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ListActivePlansAction;
use App\Actions\Billing\ListBillingHistoryAction;
use App\Actions\Billing\ResolveFullEntitlementsAction;
use App\Actions\Billing\SyncRevenueCatSubscriberAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SyncBillingRequest;
use App\Http\Resources\SubscriptionEventResource;
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

    /**
     * List all active plans and their limits.
     *
     * For client-side plan-comparison screens (e.g. the mobile "Compare plans" screen) —
     * unlike `entitlements`, which only resolves the caller's own plan, this returns every
     * active plan's limits so comparison copy can be sourced from `plan_limits` instead of
     * hardcoded numbers. No price or marketing copy is included: pricing lives in RevenueCat /
     * the App Store / Play Store product config, outside this API's control.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": [
     *     { "key": "free", "name": "Free", "limits": { "max_stores": 1, "max_rules": 0, "sms_monthly": 0, "email_monthly": 25, "history_days": 7 } },
     *     { "key": "starter", "name": "Starter", "limits": { "max_stores": 3, "max_rules": 5, "sms_monthly": 20, "email_monthly": 250, "history_days": 30 } },
     *     { "key": "pro", "name": "Pro", "limits": { "max_stores": 10, "max_rules": null, "sms_monthly": 100, "email_monthly": 1000, "history_days": 365 } },
     *     { "key": "premium", "name": "Premium", "limits": { "max_stores": null, "max_rules": null, "sms_monthly": 500, "email_monthly": 5000, "history_days": null } }
     *   ]
     * }
     */
    public function plans(ListActivePlansAction $listActivePlans): JsonResponse
    {
        return ApiResponse::success($listActivePlans->handle());
    }

    /**
     * List this team's billing history, newest first.
     *
     * Every RevenueCat event for the team — subscription purchases, renewals, tier changes,
     * cancellations, expirations, billing issues, and SMS/AI credit top-ups. Available on
     * **every plan including Free**, deliberately ungated: a Free team may still have past
     * subscription history, and a seller's own billing records are never a paid feature.
     *
     * **Not invoices.** Billing runs through IAP, so Apple/Google are the merchant of record
     * and issue the seller's actual tax document — surface this as an informational
     * transaction list and point sellers to their App Store/Play purchase history for a tax
     * invoice. There is no invoice PDF to download and none should be built.
     *
     * `price`/`currency` are `null` whenever RevenueCat's payload carried no amount (common
     * for CANCELLATION/EXPIRATION) — render that as blank, never as a `0` charge.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": { "history": [
     *     { "id": 12, "event_type": "RENEWAL", "description": "Renewed", "product_id": "pro:monthly", "price": 19.99, "currency": "USD", "occurred_at": "2026-07-30T09:14:00+00:00" },
     *     { "id": 9, "event_type": "NON_RENEWING_PURCHASE", "description": "Credit top-up", "product_id": "sms_500", "price": 14.99, "currency": "USD", "occurred_at": "2026-07-12T18:02:00+00:00" },
     *     { "id": 4, "event_type": "INITIAL_PURCHASE", "description": "Subscription started", "product_id": "pro:monthly", "price": 19.99, "currency": "USD", "occurred_at": "2026-06-30T09:14:00+00:00" }
     *   ] }
     * }
     */
    public function history(Request $request, ListBillingHistoryAction $listBillingHistory): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::success(['history' => []]);
        }

        return ApiResponse::success([
            'history' => SubscriptionEventResource::collection($listBillingHistory->handle($team)),
        ]);
    }
}
