<?php

namespace App\Http\Requests\Admin;

use App\Models\AdminUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminUserRoleRequest extends FormRequest
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
            'role' => ['required', 'string', Rule::in([
                AdminUser::ROLE_SUPERADMIN,
                AdminUser::ROLE_SUPPORT,
                AdminUser::ROLE_READONLY,
            ])],
        ];
    }
}
