<?php

namespace App\Http\Requests\Rules;

use App\Models\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule as ValidationRule;

class StoreRuleRequest extends FormRequest
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
            'trigger' => ['required', 'string', ValidationRule::in(Rule::triggers())],
            'conditions' => ['sometimes', 'array'],
            'conditions.all' => ['sometimes', 'array'],
            'conditions.all.*.field' => ['required', 'string', ValidationRule::in(Rule::conditionFields())],
            'conditions.all.*.operator' => ['required', 'string', ValidationRule::in(Rule::conditionOperators())],
            'conditions.all.*.value' => ['required'],
            'conditions.any' => ['sometimes', 'array'],
            'conditions.any.*.field' => ['required', 'string', ValidationRule::in(Rule::conditionFields())],
            'conditions.any.*.operator' => ['required', 'string', ValidationRule::in(Rule::conditionOperators())],
            'conditions.any.*.value' => ['required'],
            'actions' => ['required', 'array', 'min:1'],
            'actions.*.type' => ['required', 'string', ValidationRule::in([
                'push', 'email', 'sms', 'notify_member', 'auto_tag',
            ])],
            'actions.*.tag' => ['required_if:actions.*.type,auto_tag', 'string', 'max:50'],
            'actions.*.user_id' => ['required_if:actions.*.type,notify_member', 'integer'],
            'sound' => ['sometimes', 'nullable', 'string', ValidationRule::in(Rule::sounds())],
            'controls' => ['sometimes', 'array'],
            'controls.cooldown_minutes' => ['sometimes', 'integer', 'min:0'],
            'controls.quiet_hours.start' => ['sometimes', 'date_format:H:i'],
            'controls.quiet_hours.end' => ['sometimes', 'date_format:H:i'],
            'controls.quiet_hours.timezone' => ['sometimes', 'timezone'],
            'controls.threshold_hours' => ['sometimes', 'integer', 'min:1'],
            'controls.digest_frequency' => ['sometimes', 'string', ValidationRule::in(['daily', 'weekly'])],
            'controls.digest_time' => ['sometimes', 'date_format:H:i'],
            'controls.digest_day_of_week' => ['sometimes', 'integer', 'between:0,6'],
            'controls.spike_count' => ['sometimes', 'integer', 'min:1'],
            'controls.spike_window_minutes' => ['sometimes', 'integer', 'min:1'],
            'controls.low_stock_threshold' => ['sometimes', 'integer', 'min:0'],
            'controls.negative_review_max_rating' => ['sometimes', 'integer', 'between:1,5'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
