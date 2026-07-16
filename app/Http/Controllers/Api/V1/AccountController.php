<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\RequestAccountDeletionAction;
use App\Actions\Account\RequestDataExportAction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Account
 *
 * GDPR-related self-service actions.
 */
class AccountController extends Controller
{
    /**
     * Request a data export.
     *
     * Queues a GDPR data export; the user receives it by email.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": "We're preparing your data export — you'll receive it by email shortly.",
     *   "data": null
     * }
     */
    public function requestDataExport(Request $request, RequestDataExportAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user);

        return ApiResponse::success(message: "We're preparing your data export — you'll receive it by email shortly.");
    }

    /**
     * Request account deletion.
     *
     * Schedules a GDPR soft-delete; the account is retained for a grace period before purge.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": "Your account has been scheduled for deletion.",
     *   "data": null
     * }
     */
    public function requestDeletion(Request $request, RequestAccountDeletionAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user);

        return ApiResponse::success(message: 'Your account has been scheduled for deletion.');
    }
}
