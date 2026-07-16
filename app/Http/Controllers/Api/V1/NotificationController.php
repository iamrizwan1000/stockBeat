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

class NotificationController extends Controller
{
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

    public function markRead(MarkNotificationsReadRequest $request, MarkNotificationsReadAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $count = $action->handle($user, $request->input('ids'));

        return ApiResponse::success(['marked_read' => $count]);
    }
}
