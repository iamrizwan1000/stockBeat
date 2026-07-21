<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\DismissAnnouncementAction;
use App\Actions\Notifications\GetActiveAnnouncementsForUserAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Http\Responses\ApiResponse;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Announcements
 */
class AnnouncementController extends Controller
{
    /**
     * List active announcements.
     *
     * In-app banners currently active (within `starts_at`/`ends_at`) and matching the caller's
     * audience rules, newest first.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "announcements": [
     *       { "id": 1, "title": "New: order-spike alerts", "body": "Premium now includes order and refund spike alerts.", "dismissible": true }
     *     ]
     *   }
     * }
     */
    public function index(Request $request, GetActiveAnnouncementsForUserAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $announcements = $action->handle($user);

        return ApiResponse::success(['announcements' => AnnouncementResource::collection($announcements)]);
    }

    /**
     * Dismiss an announcement.
     *
     * Per-user — other users targeted by the same announcement are unaffected.
     * Idempotent: dismissing twice is not an error.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": "Announcement dismissed.",
     *   "data": null
     * }
     * @response 422 scenario="not dismissible" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": { "announcement": ["This announcement can't be dismissed."] }
     * }
     */
    public function dismiss(Request $request, Announcement $announcement, DismissAnnouncementAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user, $announcement);

        return ApiResponse::success(message: 'Announcement dismissed.');
    }
}
