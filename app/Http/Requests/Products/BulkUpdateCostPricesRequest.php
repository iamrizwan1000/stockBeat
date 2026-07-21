<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateCostPricesRequest extends FormRequest
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
            'updates' => ['required', 'array', 'min:1', 'max:500'],
            'updates.*.id' => ['required', 'integer'],
            'updates.*.cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ];
    }
}
