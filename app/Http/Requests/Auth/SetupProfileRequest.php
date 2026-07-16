<?php

namespace App\Http\Requests\Auth;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetupProfileRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'sells_on' => ['required', 'array', 'min:1'],
            'sells_on.*' => ['string', Rule::in(['shopify', 'woo', 'ebay', 'etsy', 'amazon'])],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'base_currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
