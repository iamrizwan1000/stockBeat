<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\VerifySocialSignInAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialSignInRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * @group Auth — Social sign-in
 *
 * Apple/Google one-tap, an alternative to email OTP. Both providers converge on the same
 * user record as OTP by verified email — signing in with a different method never creates
 * a second account.
 */
class SocialAuthController extends Controller
{
    /**
     * Sign in with Apple or Google.
     *
     * Verifies the client-obtained id_token server-side, then issues a Sanctum bearer token.
     * Same response shape as `/auth/otp/verify` — `is_new_user` tells the client whether to
     * route into `/profile/setup` next.
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
     * @response 422 scenario="invalid token" {
     *   "success": false,
     *   "message": "This sign-in token is invalid or has expired.",
     *   "errors": {
     *     "id_token": ["This sign-in token is invalid or has expired."]
     *   }
     * }
     */
    public function signIn(SocialSignInRequest $request, VerifySocialSignInAction $action): JsonResponse
    {
        $result = $action->handle(
            $request->string('provider')->toString(),
            $request->string('id_token')->toString(),
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
