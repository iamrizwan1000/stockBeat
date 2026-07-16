<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Settings\UpdateNotificationPreferencesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNotificationPreferencesRequest;
use App\Http\Resources\NotificationPreferenceResource;
use App\Http\Responses\ApiResponse;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Settings
 */
class SettingsController extends Controller
{
    /**
     * Get notification preferences.
     *
     * Returns defaults (all channels enabled, no quiet hours) if the user has never saved any.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "preferences": {
     *       "push_enabled": true,
     *       "email_enabled": true,
     *       "sms_enabled": false,
     *       "quiet_hours_start": "21:00",
     *       "quiet_hours_end": "07:00",
     *       "quiet_hours_timezone": "Australia/Sydney",
     *       "sound": "default"
     *     }
     *   }
     * }
     */
    public function showNotificationPreferences(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $preference = NotificationPreference::query()->firstOrNew(['user_id' => $user->id]);

        return ApiResponse::success(['preferences' => new NotificationPreferenceResource($preference)]);
    }

    /**
     * Update notification preferences.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "preferences": {
     *       "push_enabled": true,
     *       "email_enabled": false,
     *       "sms_enabled": false,
     *       "quiet_hours_start": "21:00",
     *       "quiet_hours_end": "07:00",
     *       "quiet_hours_timezone": "Australia/Sydney",
     *       "sound": "chime"
     *     }
     *   }
     * }
     */
    public function updateNotificationPreferences(UpdateNotificationPreferencesRequest $request, UpdateNotificationPreferencesAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $preference = $action->handle($user, $request->validated());

        return ApiResponse::success(['preferences' => new NotificationPreferenceResource($preference)]);
    }
}
