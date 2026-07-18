<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CreateFeatureFlagRequest extends FormRequest
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
            // Stable slug: lowercase letters/digits + underscores, can't be
            // changed after creation (see UpdateFeatureFlagRequest).
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:feature_flags,key'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'enabled' => ['sometimes', 'boolean'],
            'rollout_percentage' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'enabled_for_team_ids' => ['nullable', 'array'],
            'enabled_for_team_ids.*' => ['integer'],
        ];
    }
}
