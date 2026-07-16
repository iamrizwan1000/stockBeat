<?php

namespace App\Http\Resources;

use App\Models\NotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationPreference
 */
class NotificationPreferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'push_enabled' => $this->push_enabled,
            'email_enabled' => $this->email_enabled,
            'sms_enabled' => $this->sms_enabled,
            'quiet_hours_start' => $this->quiet_hours_start,
            'quiet_hours_end' => $this->quiet_hours_end,
            'quiet_hours_timezone' => $this->quiet_hours_timezone,
            'sound' => $this->sound,
        ];
    }
}
