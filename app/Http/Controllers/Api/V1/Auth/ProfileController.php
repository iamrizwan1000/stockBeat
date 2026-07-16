<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\SetupProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetupProfileRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * @group Auth — Profile
 */
class ProfileController extends Controller
{
    /**
     * Complete profile setup.
     *
     * One-time step for new users after OTP verification. Creates the user's owning `Team`
     * (with the user as `owner`) alongside setting their profile fields.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
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
     */
    public function setup(SetupProfileRequest $request, SetupProfileAction $action): JsonResponse
    {
        /** @var User $authenticatedUser */
        $authenticatedUser = $request->user();

        $user = $action->handle(
            user: $authenticatedUser,
            name: $request->string('name')->toString(),
            businessName: $request->string('business_name')->toString() ?: null,
            sellsOn: $request->input('sells_on'),
            timezone: $request->string('timezone')->toString() ?: null,
            baseCurrency: $request->string('base_currency')->toString() ?: null,
            phone: $request->string('phone')->toString() ?: null,
        );

        return ApiResponse::success([
            'user' => new UserResource($user),
        ]);
    }
}
