<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutResource;
use App\Http\Responses\ApiResponse;
use App\Models\Payout;
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
    /**
     * List this team's polled payouts, newest first.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": { "payouts": [
     *     { "id": 1, "connection_id": 1, "amount": 482.19, "currency": "USD", "status": "paid", "arrival_date": "2026-07-24" }
     *   ] }
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
