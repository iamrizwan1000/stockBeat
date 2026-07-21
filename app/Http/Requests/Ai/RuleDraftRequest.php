<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class RuleDraftRequest extends FormRequest
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
            'prompt' => ['required', 'string', 'max:500'],
        ];
    }
}
