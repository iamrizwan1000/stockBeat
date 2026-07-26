<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\ListCustomersAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Customers
 *
 * A query-time aggregation over `orders` (Plan §4.16) — no new table.
 * Read-only end to end; for a customer's full order history, use
 * `GET /orders?customer_email=...` (`orders-api-reference.md`), not a
 * separate endpoint here.
 */
class CustomerController extends Controller
{
    /**
     * List this team's customers, grouped by email, most recent order first.
     */
    public function index(Request $request, ListCustomersAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::success(['customers' => []]);
        }

        $customers = $action->handle($team, $user->currentTeamMember());

        return ApiResponse::success(['customers' => CustomerResource::collection($customers)]);
    }
}
