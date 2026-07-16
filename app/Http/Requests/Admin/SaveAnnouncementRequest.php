<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveAnnouncementRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'audience' => ['nullable', 'array'],
            'audience.plan' => ['nullable', 'string'],
            'audience.platform' => ['nullable', 'string'],
            'audience.inactive_days_gte' => ['nullable', 'integer', 'min:1'],
            'audience.trial_ending_within_days' => ['nullable', 'integer', 'min:1'],
            'audience.marketing_opt_in' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'dismissible' => ['sometimes', 'boolean'],
        ];
    }
}
