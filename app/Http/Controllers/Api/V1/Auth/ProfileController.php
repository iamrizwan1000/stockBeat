<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\SetupProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetupProfileRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
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
