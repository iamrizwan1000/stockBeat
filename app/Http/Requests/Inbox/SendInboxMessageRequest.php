<?php

namespace App\Http\Requests\Inbox;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendInboxMessageRequest extends FormRequest
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
        /** @var User|null $user */
        $user = $this->user();
        $teamId = $user?->currentTeam()?->id;

        return [
            'body' => ['required_without:reply_template_id', 'nullable', 'string', 'max:4000'],
            'reply_template_id' => [
                'nullable',
                'integer',
                Rule::exists('reply_templates', 'id')->where('team_id', $teamId),
            ],
        ];
    }
}
