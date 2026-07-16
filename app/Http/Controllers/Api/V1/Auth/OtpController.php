<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RequestOtpAction;
use App\Actions\Auth\VerifyOtpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * @group Auth — OTP
 *
 * Passwordless sign-in: request a one-time code by email, then verify it for a bearer token.
 */
class OtpController extends Controller
{
    /**
     * Request an OTP code.
     *
     * Always returns 200 regardless of whether the email is registered, to avoid account enumeration.
     * Rate limited (`otp-request`); a fresh code can be requested every 30s.
     *
     * @unauthenticated
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": "A verification code has been sent if the email is registered.",
     *   "data": null
     * }
     */
    public function request(RequestOtpRequest $request, RequestOtpAction $action): JsonResponse
    {
        $action->handle($request->string('email')->toString(), $request->ip());

        return ApiResponse::success(message: 'A verification code has been sent if the email is registered.');
    }

    /**
     * Verify an OTP code.
     *
     * Returns a Sanctum bearer token on success. `is_new_user` tells the client whether to route
     * into `/profile/setup` next. Rate limited (`otp-verify`); locks out after 5 failed attempts.
     *
     * @unauthenticated
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "token": "1|abcdef1234567890",
     *     "is_new_user": false,
     *     "user": {
     *       "id": 1,
     *       "name": "Jamie Rivera",
     *       "email": "jamie@example.com",
     *       "business_name": "Rivera Vintage Co",
     *       "base_currency": "AUD",
     *       "timezone": "Australia/Sydney",
     *       "sells_on": ["woocommerce"]
     *     }
     *   }
     * }
     * @response 422 scenario="invalid or expired code" {
     *   "success": false,
     *   "message": "That code is invalid or has expired.",
     *   "errors": null
     * }
     */
    public function verify(VerifyOtpRequest $request, VerifyOtpAction $action): JsonResponse
    {
        $result = $action->handle(
            $request->string('email')->toString(),
            $request->string('code')->toString(),
            $request->userAgent() ?? 'mobile',
            $request->ip(),
        );

        return ApiResponse::success([
            'token' => $result['token'],
            'is_new_user' => $result['is_new_user'],
            'user' => new UserResource($result['user']),
        ]);
    }
}
