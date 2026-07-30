<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class GrantEmailCreditsRequest extends FormRequest
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
            'credits' => ['required', 'integer', 'min:1', 'max:10000'],
            'notify_customer' => ['sometimes', 'boolean'],
            'notify_channels' => ['required_if:notify_customer,true', 'array'],
            'notify_channels.*' => ['in:push,email,sms'],
            'notify_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
