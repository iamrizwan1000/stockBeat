<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Analytics\GetAnalyticsSummaryAction;
use App\Actions\Analytics\GetTopProductsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\AnalyticsRangeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    public function summary(AnalyticsRangeRequest $request, GetAnalyticsSummaryAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup first.', status: 422);
        }

        return ApiResponse::success($action->handle($team, $request->range()));
    }

    public function products(AnalyticsRangeRequest $request, GetTopProductsAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup first.', status: 422);
        }

        return ApiResponse::success(['products' => $action->handle($team, $request->range())]);
    }
}
