<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\RequestAccountDeletionAction;
use App\Actions\Account\RequestDataExportAction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function requestDataExport(Request $request, RequestDataExportAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user);

        return ApiResponse::success(message: "We're preparing your data export — you'll receive it by email shortly.");
    }

    public function requestDeletion(Request $request, RequestAccountDeletionAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user);

        return ApiResponse::success(message: 'Your account has been scheduled for deletion.');
    }
}
