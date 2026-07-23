<?php

namespace App\Http\Requests\Connections;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConnectionMuteRequest extends FormRequest
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
            'notifications_muted' => ['required', 'boolean'],
        ];
    }
}
