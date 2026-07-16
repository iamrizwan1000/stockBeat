<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Support\GetOrCreateSupportThreadAction;
use App\Actions\Support\SendUserSupportMessageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\SendSupportMessageRequest;
use App\Http\Resources\SupportMessageResource;
use App\Http\Responses\ApiResponse;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Support
 *
 * Live support chat (Plan §4.9). One thread per user, resumed across visits —
 * never a fresh thread. Internal staff notes (`direction=note`) are never
 * returned here.
 */
class SupportController extends Controller
{
    /**
     * Get (or start) the support thread.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "thread": { "id": 1, "status": "open" },
     *     "messages": [
     *       { "id": 1, "direction": "user", "body": "My orders stopped syncing", "attachments": null, "created_at": "2026-07-16T02:00:00.000000Z" }
     *     ]
     *   }
     * }
     */
    public function show(Request $request, GetOrCreateSupportThreadAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $thread = $action->handle($user);
        $messages = $thread->messages()->where('direction', '!=', SupportMessage::DIRECTION_NOTE)->oldest('created_at')->get();

        return ApiResponse::success([
            'thread' => ['id' => $thread->id, 'status' => $thread->status],
            'messages' => SupportMessageResource::collection($messages),
        ]);
    }

    /**
     * Send a message.
     *
     * Reopens the thread if it was previously resolved.
     *
     * @response 201 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "message": { "id": 2, "direction": "user", "body": "Any update?", "attachments": null, "created_at": "2026-07-16T02:05:00.000000Z" }
     *   }
     * }
     */
    public function store(SendSupportMessageRequest $request, SendUserSupportMessageAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $message = $action->handle($user, $request->string('body')->toString());

        return ApiResponse::success(['message' => new SupportMessageResource($message)], status: 201);
    }
}
