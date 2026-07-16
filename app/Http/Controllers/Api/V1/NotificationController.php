<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\MarkNotificationsReadAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\MarkNotificationsReadRequest;
use App\Http\Resources\NotificationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Notifications
 */
class NotificationController extends Controller
{
    /**
     * List notifications.
     *
     * Most recent 50, newest first.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "notifications": [
     *       {
     *         "id": 1,
     *         "type": "rule_fired",
     *         "title": "High-value order",
     *         "body": "Order #1042 — $84.00",
     *         "data": { "order_id": 1, "rule_id": 1 },
     *         "read_at": null,
     *         "created_at": "2026-07-16T01:00:00.000000Z"
     *       }
     *     ]
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $notifications = Notification::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(50)
            ->get();

        return ApiResponse::success(['notifications' => NotificationResource::collection($notifications)]);
    }

    /**
     * Mark notifications as read.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": { "marked_read": 3 }
     * }
     */
    public function markRead(MarkNotificationsReadRequest $request, MarkNotificationsReadAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $count = $action->handle($user, $request->input('ids'));

        return ApiResponse::success(['marked_read' => $count]);
    }
}
