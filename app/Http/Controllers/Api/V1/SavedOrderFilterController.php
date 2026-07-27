<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\CreateSavedOrderFilterAction;
use App\Actions\Orders\DeleteSavedOrderFilterAction;
use App\Actions\Orders\UpdateSavedOrderFilterAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\SaveOrderFilterRequest;
use App\Http\Resources\SavedOrderFilterResource;
use App\Http\Responses\ApiResponse;
use App\Models\SavedOrderFilter;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Orders
 *
 * Favorite/saved order filters (Plan §4.23, added 2026-07-27) — a named,
 * team-shared preset over the exact same params `GET /orders` already
 * accepts (`orders-api-reference.md`). Free on every plan — this sits on
 * top of the order feed & filters, which are already free everywhere, so
 * gating it would be an arbitrary inconsistency.
 */
class SavedOrderFilterController extends Controller
{
    /**
     * List this team's saved filters.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "filters": [
     *       { "id": 1, "name": "Unfulfilled Shopify", "filters": { "channel": "shopify", "status": "unfulfilled" }, "created_at": "2026-07-27T00:00:00.000000Z" }
     *     ]
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::success(['filters' => []]);
        }

        $filters = SavedOrderFilter::query()->where('team_id', $team->id)->orderBy('name')->get();

        return ApiResponse::success(['filters' => SavedOrderFilterResource::collection($filters)]);
    }

    /**
     * Save a new filter preset.
     *
     * @response 201 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "filter": { "id": 2, "name": "High-value Etsy", "filters": { "channel": "etsy", "value_min": 100 }, "created_at": "2026-07-27T00:00:00.000000Z" }
     *   }
     * }
     */
    public function store(SaveOrderFilterRequest $request, CreateSavedOrderFilterAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup before saving a filter.', status: 422);
        }

        $filter = $action->handle($team, $request->string('name')->toString(), $request->validated('filters'));

        return ApiResponse::success(['filter' => new SavedOrderFilterResource($filter)], status: 201);
    }

    public function update(SaveOrderFilterRequest $request, SavedOrderFilter $savedOrderFilter, UpdateSavedOrderFilterAction $action): JsonResponse
    {
        $this->authorizeFilterAccess($request, $savedOrderFilter);

        $filter = $action->handle($savedOrderFilter, $request->string('name')->toString(), $request->validated('filters'));

        return ApiResponse::success(['filter' => new SavedOrderFilterResource($filter)]);
    }

    public function destroy(Request $request, SavedOrderFilter $savedOrderFilter, DeleteSavedOrderFilterAction $action): JsonResponse
    {
        $this->authorizeFilterAccess($request, $savedOrderFilter);

        $action->handle($savedOrderFilter);

        return ApiResponse::success(message: 'Saved filter deleted.');
    }

    private function authorizeFilterAccess(Request $request, SavedOrderFilter $filter): void
    {
        /** @var User $user */
        $user = $request->user();

        if ($filter->team_id !== $user->currentTeam()?->id) {
            abort(404);
        }
    }
}
