<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\GetActiveAnnouncementsForUserAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Http\Responses\ApiResponse;
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
}
