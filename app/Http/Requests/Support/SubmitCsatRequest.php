<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

class SubmitCsatRequest extends FormRequest
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
            // 0 = 👎, 1 = 👍 (Plan §4.9/§8.7.6).
            'rating' => ['required', 'integer', 'in:0,1'],
        ];
    }
}
