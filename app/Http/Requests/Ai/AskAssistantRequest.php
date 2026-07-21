<?php

namespace App\Http\Requests\Ai;

use App\Actions\Ai\AskAssistantAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AskAssistantRequest extends FormRequest
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
            'question' => ['required', 'string', 'max:1000'],
            'conversation_id' => ['sometimes', 'nullable', 'integer', 'exists:ai_conversations,id'],
            'mode' => ['sometimes', 'string', Rule::in([AskAssistantAction::MODE_DATA, AskAssistantAction::MODE_HELP])],
        ];
    }
}
