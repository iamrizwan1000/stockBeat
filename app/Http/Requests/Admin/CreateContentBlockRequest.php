<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CreateContentBlockRequest extends FormRequest
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
            // Stable slug the mobile `content` map is keyed by — can't be
            // changed after creation (see UpdateContentBlockRequest).
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:content_blocks,key'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'locale' => ['sometimes', 'string', 'max:10'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
