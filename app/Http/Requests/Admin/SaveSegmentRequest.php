<?php

namespace App\Http\Requests\Admin;

use App\Models\StoreConnection;
use App\Models\Subscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSegmentRequest extends FormRequest
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
            'filters' => ['nullable', 'array'],
            'filters.plan' => ['nullable', 'string', Rule::in([
                'free',
                Subscription::STATUS_TRIAL,
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_GRACE,
                Subscription::STATUS_EXPIRED,
            ])],
            'filters.platform' => ['nullable', 'string', Rule::in([
                StoreConnection::PLATFORM_SHOPIFY,
                StoreConnection::PLATFORM_WOO,
                StoreConnection::PLATFORM_EBAY,
                StoreConnection::PLATFORM_ETSY,
                StoreConnection::PLATFORM_AMAZON,
                StoreConnection::PLATFORM_TIKTOK,
            ])],
            'filters.inactive_days_gte' => ['nullable', 'integer', 'min:1'],
            'filters.trial_ending_within_days' => ['nullable', 'integer', 'min:1'],
            'filters.marketing_opt_in' => ['nullable', 'boolean'],
        ];
    }
}
