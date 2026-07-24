<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\GetUsageSummaryAction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Usage
 *
 * Usage-history view over SMS/AI-question/email quotas — a companion to
 * `GET /me`/`GET /billing/entitlements`, which only report the current
 * standing balance. This adds how much of this month's allotment has been
 * used (`pct_used`, `quota_warning` once at 80%+) and a 30-day daily
 * breakdown for a usage graph.
 */
class UsageController extends Controller
{
    /**
     * Get the team's usage summary across SMS, AI questions, and email.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "sms": {
     *       "balance": 42,
     *       "plan_monthly_allotment": 100,
     *       "used_this_month": 8,
     *       "pct_used": 8.0,
     *       "quota_warning": false,
     *       "daily": [{"date": "2026-06-25", "count": 0}, {"date": "2026-07-24", "count": 2}]
     *     },
     *     "ai_questions": {
     *       "limit": 150,
     *       "used_this_month": 12,
     *       "remaining": 138,
     *       "pct_used": 8.0,
     *       "quota_warning": false,
     *       "daily": [{"date": "2026-06-25", "count": 0}, {"date": "2026-07-24", "count": 1}]
     *     },
     *     "emails": {
     *       "limit": 1000,
     *       "used_this_month": 220,
     *       "remaining": 780,
     *       "pct_used": 22.0,
     *       "quota_warning": false,
     *       "daily": [{"date": "2026-06-25", "count": 0}, {"date": "2026-07-24", "count": 6}]
     *     }
     *   }
     * }
     * @response 422 scenario="profile setup not complete" {
     *   "success": false,
     *   "message": "Complete profile setup first.",
     *   "errors": null
     * }
     */
    public function summary(Request $request, GetUsageSummaryAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup first.', status: 422);
        }

        return ApiResponse::success($action->handle($team));
    }
}
