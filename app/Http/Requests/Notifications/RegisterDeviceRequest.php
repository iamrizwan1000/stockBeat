<?php

namespace App\Http\Requests\Notifications;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', Rule::in([Device::PLATFORM_IOS, Device::PLATFORM_ANDROID])],
            'push_token' => ['required', 'string', 'max:255'],
        ];
    }
}
