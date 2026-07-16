<?php

namespace App\Http\Requests\Admin;

use App\Models\PromoCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePromoCampaignRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in([
                PromoCampaign::TYPE_OFFER_CODE,
                PromoCampaign::TYPE_INTRO_OFFER,
                PromoCampaign::TYPE_SERVER_COMP,
            ])],
            'store_ref' => ['nullable', 'string', Rule::in([PromoCampaign::STORE_APPLE, PromoCampaign::STORE_GOOGLE])],
            'config' => ['nullable', 'array'],
            'config.code_prefix' => ['nullable', 'string', 'max:64'],
            'config.discount_pct' => ['nullable', 'integer', 'min:1', 'max:100'],
            'config.duration_months' => ['nullable', 'integer', 'min:1'],
            'config.intro_price' => ['nullable', 'numeric', 'min:0'],
            'config.intro_duration' => ['nullable', 'string', 'max:64'],
            'config.comp_type' => ['nullable', 'string', Rule::in([
                PromoCampaign::COMP_TYPE_PRO_DAYS,
                PromoCampaign::COMP_TYPE_SMS_CREDITS,
            ])],
            'config.amount' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
