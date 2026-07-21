<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiProviderSettingRequest extends FormRequest
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
            'api_key' => ['sometimes', 'nullable', 'string'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'activate' => ['sometimes', 'boolean'],
        ];
    }
}
