<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCostPriceRequest extends FormRequest
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
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ];
    }
}
