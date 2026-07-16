<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\RegisterDeviceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\RegisterDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * @group Devices
 */
class DeviceController extends Controller
{
    /**
     * Register a push device.
     *
     * Upserts the device's push token so the backend can deliver notifications to it.
     *
     * @response 201 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "device": { "id": 1, "platform": "ios", "last_seen_at": "2026-07-16T02:00:00.000000Z" }
     *   }
     * }
     */
    public function store(RegisterDeviceRequest $request, RegisterDeviceAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $device = $action->handle(
            $user,
            $request->string('platform')->toString(),
            $request->string('push_token')->toString(),
        );

        return ApiResponse::success(['device' => new DeviceResource($device)], status: 201);
    }
}
