<?php

namespace App\Http\Requests\Admin;

use App\Models\AdminUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateAdminUserRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255', 'unique:admin_users,email'],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', 'string', Rule::in([
                AdminUser::ROLE_SUPERADMIN,
                AdminUser::ROLE_SUPPORT,
                AdminUser::ROLE_READONLY,
            ])],
        ];
    }
}
