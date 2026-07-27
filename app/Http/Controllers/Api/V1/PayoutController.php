<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutResource;
use App\Http\Responses\ApiResponse;
use App\Models\Payout;
use App\Models\PaywallHit;
use App\Models\PlanLimit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Payouts
 *
 * Real payout events (Plan §4.14) — what actually landed in the seller's
 * bank account, distinct from order revenue. Read-only end to end: no
 * create/update/delete, this is purely a reconciliation view. Shopify only
 * in v1 — other platforms simply have no rows here yet, not an error.
 */
class PayoutController extends Controller
{
    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * List this team's polled payouts, newest first.
     *
     * Requires the plan's `analytics_level` to be `full` (Pro+, Plan §4.14/§5) —
     * this was specified from the start but not actually enforced until this fix.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": { "payouts": [
     *     { "id": 1, "connection_id": 1, "amount": 482.19, "currency": "USD", "status": "paid", "arrival_date": "2026-07-24" }
     *   ] }
     * }
     * @response 403 scenario="plan does not include payouts" {
     *   "success": false,
     *   "message": "Payouts requires the Pro plan or higher.",
     *   "errors": null
     * }
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::success(['payouts' => []]);
        }

        $limits = $this->resolveEntitlements->handle($team)['limits'];

        if (($limits['analytics_level'] ?? null) !== 'full') {
            PaywallHit::log($team, PlanLimit::ANALYTICS_LEVEL);

            return ApiResponse::error('Payouts requires the Pro plan or higher.', status: 403);
        }

        $payouts = Payout::query()
            ->where('team_id', $team->id)
            ->when(
                $request->filled('connection_id'),
                fn ($query) => $query->where('connection_id', $request->integer('connection_id')),
            )
            ->orderByDesc('arrival_date')
            ->get();

        return ApiResponse::success(['payouts' => PayoutResource::collection($payouts)]);
    }
}
