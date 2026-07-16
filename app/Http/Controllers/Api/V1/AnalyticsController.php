<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Analytics\GetAnalyticsSummaryAction;
use App\Actions\Analytics\GetTopProductsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\AnalyticsRangeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * @group Analytics
 *
 * Analytics-lite (Plan §4.6): today/7d/30d revenue + order count + AOV, gated by plan
 * (`analytics_level`: Free=today only, Starter=+7d, Pro/Premium=full with comparison + goal
 * tracking). "Today" is always computed live, never from the `daily_stats` pre-aggregation.
 */
class AnalyticsController extends Controller
{
    /**
     * Get the analytics summary.
     *
     * `comparison` and `goal` are only present at the `full` analytics level (Pro/Premium).
     *
     * @queryParam range string required One of `today`, `7d`, `30d`. Example: 7d
     *
     * @response 200 scenario="full (Pro/Premium)" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "range": "7d",
     *     "total": { "revenue": 1840.0, "revenue_base": 1840.0, "orders_count": 23, "aov": 80.0 },
     *     "by_channel": [
     *       { "connection_id": 1, "platform": "woo", "name": "Rivera Vintage Co", "revenue": 1840.0, "revenue_base": 1840.0, "orders_count": 23, "aov": 80.0 }
     *     ],
     *     "comparison": { "previous_period_revenue": 1500.0, "change_pct": 22.7 },
     *     "goal": { "current_month_revenue": 4200.0, "best_month_revenue": 6100.0, "pct_of_best_month": 68.9 }
     *   }
     * }
     * @response 200 scenario="today only (Free)" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "range": "today",
     *     "total": { "revenue": 240.0, "revenue_base": 240.0, "orders_count": 3, "aov": 80.0 },
     *     "by_channel": [
     *       { "connection_id": 1, "platform": "woo", "name": "Rivera Vintage Co", "revenue": 240.0, "revenue_base": 240.0, "orders_count": 3, "aov": 80.0 }
     *     ]
     *   }
     * }
     * @response 422 scenario="range not allowed on this plan" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": { "range": ["Upgrade your plan for more analytics history."] }
     * }
     */
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

    /**
     * Get top products.
     *
     * Grouped by SKU, falling back to title for SKU-less line items. Same plan gating as the summary.
     *
     * @queryParam range string required One of `today`, `7d`, `30d`. Example: 7d
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "products": [
     *       { "sku": "VNT-014", "title": "Vintage Denim Jacket", "units": 12, "revenue": 1008.0 }
     *     ]
     *   }
     * }
     */
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
