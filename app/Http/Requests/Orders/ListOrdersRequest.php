<?php

namespace App\Http\Requests\Orders;

use App\Models\Order;
use App\Models\StoreConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends FormRequest
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
            'channel' => ['sometimes', 'string', Rule::in([
                StoreConnection::PLATFORM_SHOPIFY,
                StoreConnection::PLATFORM_WOO,
                StoreConnection::PLATFORM_EBAY,
                StoreConnection::PLATFORM_ETSY,
                StoreConnection::PLATFORM_AMAZON,
                StoreConnection::PLATFORM_TIKTOK,
            ])],
            'store' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', Rule::in([
                Order::STATUS_NEW,
                Order::STATUS_UNFULFILLED,
                Order::STATUS_SHIPPED,
                Order::STATUS_REFUNDED,
                Order::STATUS_CANCELLED,
            ])],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'value_min' => ['sometimes', 'numeric'],
            'value_max' => ['sometimes', 'numeric'],
            'tag' => ['sometimes', 'string'],
            'q' => ['sometimes', 'string'],
            'include_snoozed' => ['sometimes', 'boolean'],
        ];
    }
}
