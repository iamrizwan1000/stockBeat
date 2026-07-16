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

class SettingsController extends Controller
{
    public function showNotificationPreferences(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $preference = NotificationPreference::query()->firstOrNew(['user_id' => $user->id]);

        return ApiResponse::success(['preferences' => new NotificationPreferenceResource($preference)]);
    }

    public function updateNotificationPreferences(UpdateNotificationPreferencesRequest $request, UpdateNotificationPreferencesAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $preference = $action->handle($user, $request->validated());

        return ApiResponse::success(['preferences' => new NotificationPreferenceResource($preference)]);
    }
}
