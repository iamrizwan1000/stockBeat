<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\LogoutAction;
use App\Actions\Auth\LogoutAllDevicesAction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Auth — Session
 */
class SessionController extends Controller
{
    /**
     * Log out.
     *
     * Revokes the bearer token used for this request only.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": "Logged out.",
     *   "data": null
     * }
     */
    public function logout(Request $request, LogoutAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user);

        return ApiResponse::success(message: 'Logged out.');
    }

    /**
     * Log out of all devices.
     *
     * Revokes every Sanctum token for the user, not just the current one.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": "Logged out of all devices.",
     *   "data": null
     * }
     */
    public function logoutAll(Request $request, LogoutAllDevicesAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user);

        return ApiResponse::success(message: 'Logged out of all devices.');
    }
}
