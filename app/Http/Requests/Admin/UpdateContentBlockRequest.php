<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContentBlockRequest extends FormRequest
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
            // `key` is intentionally absent — it's immutable after creation.
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'locale' => ['sometimes', 'string', 'max:10'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
