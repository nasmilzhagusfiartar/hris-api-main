<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class AttendanceCheckOutRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employee_profiles,id'],
            'check_out_lat' => ['required', 'numeric'],
            'check_out_long' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.required' => 'Your account is not linked to an employee profile.',
        ];
    }

    public function attributes()
    {
        return [
            'employee_id' => 'Employee',
            'check_out_lat' => 'Latitude',
            'check_out_long' => 'Longitude',
            'notes' => 'Notes',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'employee_id' => Auth::user()?->employeeProfile?->id,
        ]);
    }
}
