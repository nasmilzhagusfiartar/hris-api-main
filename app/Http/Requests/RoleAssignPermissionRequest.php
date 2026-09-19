<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RoleAssignPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'permissions.required' => 'Daftar permissions wajib diisi.',
            'permissions.array' => 'Permissions harus berupa array.',
        ];
    }
}
