<?php

namespace App\Http\Requests\Orders;

use App\Models\Order;
use App\Models\StoreConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared by both create and update, same convention as
 * `SaveReplyTemplateRequest`. `filters.*` mirrors `ListOrdersRequest`'s own
 * field vocabulary exactly — a saved filter is just a persisted subset of
 * those same params, never a parallel filtering language to keep in sync.
 */
class SaveOrderFilterRequest extends FormRequest
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
            'filters' => ['required', 'array'],
            'filters.channel' => ['sometimes', 'string', Rule::in([
                StoreConnection::PLATFORM_SHOPIFY,
                StoreConnection::PLATFORM_WOO,
                StoreConnection::PLATFORM_EBAY,
                StoreConnection::PLATFORM_ETSY,
                StoreConnection::PLATFORM_AMAZON,
                StoreConnection::PLATFORM_TIKTOK,
            ])],
            'filters.store' => ['sometimes', 'integer'],
            'filters.status' => ['sometimes', 'string', Rule::in([
                Order::STATUS_NEW,
                Order::STATUS_UNFULFILLED,
                Order::STATUS_SHIPPED,
                Order::STATUS_REFUNDED,
                Order::STATUS_CANCELLED,
            ])],
            'filters.date_from' => ['sometimes', 'date'],
            'filters.date_to' => ['sometimes', 'date'],
            'filters.value_min' => ['sometimes', 'numeric'],
            'filters.value_max' => ['sometimes', 'numeric'],
            'filters.tag' => ['sometimes', 'string'],
            'filters.q' => ['sometimes', 'string'],
            'filters.customer_email' => ['sometimes', 'string', 'email'],
            'filters.include_snoozed' => ['sometimes', 'boolean'],
        ];
    }
}
