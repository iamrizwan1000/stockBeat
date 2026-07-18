<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSmsTopupPackRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'sms_credits' => ['required', 'integer', 'min:1'],
            'price_usd' => ['required', 'numeric', 'min:0.01'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
