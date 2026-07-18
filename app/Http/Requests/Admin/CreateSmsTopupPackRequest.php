<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CreateSmsTopupPackRequest extends FormRequest
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
            // Stable slug matching the RevenueCat product id (e.g. sms_100)
            // — can't be changed after creation (see UpdateSmsTopupPackRequest).
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:sms_topup_packs,key'],
            'name' => ['required', 'string', 'max:255'],
            'sms_credits' => ['required', 'integer', 'min:1'],
            'price_usd' => ['required', 'numeric', 'min:0.01'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
