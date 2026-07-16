<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\RegisterDeviceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\RegisterDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
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
