<?php

namespace App\Http\Requests\Admin;

use App\Models\AppConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppConfigRequest extends FormRequest
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
            'key' => ['required', 'string', Rule::in([
                AppConfig::KEY_MIN_VERSION,
                AppConfig::KEY_MAINTENANCE_MODE,
                AppConfig::KEY_MAINTENANCE_BANNER,
            ])],
            'value' => ['nullable'],
        ];
    }
}
