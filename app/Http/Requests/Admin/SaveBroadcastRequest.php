<?php

namespace App\Http\Requests\Admin;

use App\Models\Broadcast;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBroadcastRequest extends FormRequest
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
            'audience_type' => ['required', 'string', Rule::in([
                Broadcast::AUDIENCE_ALL,
                Broadcast::AUDIENCE_SEGMENT,
                Broadcast::AUDIENCE_USER,
            ])],
            'segment_id' => ['required_if:audience_type,'.Broadcast::AUDIENCE_SEGMENT, 'nullable', 'integer', 'exists:segments,id'],
            'user_id' => ['required_if:audience_type,'.Broadcast::AUDIENCE_USER, 'nullable', 'integer', 'exists:users,id'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in([
                Broadcast::CHANNEL_PUSH,
                Broadcast::CHANNEL_EMAIL,
                Broadcast::CHANNEL_BANNER,
            ])],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
