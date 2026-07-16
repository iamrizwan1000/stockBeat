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

class OtpController extends Controller
{
    public function request(RequestOtpRequest $request, RequestOtpAction $action): JsonResponse
    {
        $action->handle($request->string('email')->toString(), $request->ip());

        return ApiResponse::success(message: 'A verification code has been sent if the email is registered.');
    }

    public function verify(VerifyOtpRequest $request, VerifyOtpAction $action): JsonResponse
    {
        $result = $action->handle(
            $request->string('email')->toString(),
            $request->string('code')->toString(),
            $request->userAgent() ?? 'mobile',
        );

        return ApiResponse::success([
            'token' => $result['token'],
            'is_new_user' => $result['is_new_user'],
            'user' => new UserResource($result['user']),
        ]);
    }
}
