<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\LogoutAction;
use App\Actions\Auth\LogoutAllDevicesAction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function logout(Request $request, LogoutAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user);

        return ApiResponse::success(message: 'Logged out.');
    }

    public function logoutAll(Request $request, LogoutAllDevicesAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user);

        return ApiResponse::success(message: 'Logged out of all devices.');
    }
}
