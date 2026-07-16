<?php

namespace App\Http\Requests\Teams;

use App\Models\TeamMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule as ValidationRule;

class InviteTeamMemberRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', ValidationRule::in([
                TeamMember::ROLE_MANAGER, TeamMember::ROLE_AGENT, TeamMember::ROLE_VIEWER,
            ])],
            'store_visibility' => ['sometimes', 'nullable', 'array'],
            'store_visibility.*' => ['integer'],
        ];
    }
}
