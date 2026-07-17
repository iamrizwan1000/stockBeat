<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Config\GetAppConfigForMobileAction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * @group Config
 *
 * Remote app config (Plan §10/§17.7) — deliberately unauthenticated so a
 * killed app version or a maintenance banner can be checked before the
 * user has signed in.
 */
class ConfigController extends Controller
{
    /**
     * Get remote config.
     *
     * @unauthenticated
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "min_version": "1.2.0",
     *     "maintenance_mode": false,
     *     "maintenance_banner": null
     *   }
     * }
     */
    public function show(GetAppConfigForMobileAction $action): JsonResponse
    {
        return ApiResponse::success($action->handle());
    }
}
