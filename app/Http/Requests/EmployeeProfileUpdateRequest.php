<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\BankName;
use App\Enums\Department;
use App\Enums\EducationLevel;
use App\Enums\EmploymentType;
use App\Enums\Gender;
use App\Enums\JobStatus;
use App\Enums\SkillLevel;
use App\Enums\WorkLocation;
use App\Models\EmployeeProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeProfileUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('educations'))) {
            $normalized = array_map(function ($edu) {
                if (! is_array($edu)) {
                    return $edu;
                }
                if (array_key_exists('end_year', $edu) && ($edu['end_year'] === '' || $edu['end_year'] === null)) {
                    $edu['end_year'] = null;
                }
                if (array_key_exists('gpa', $edu) && ($edu['gpa'] === '' || $edu['gpa'] === null)) {
                    $edu['gpa'] = null;
                }
                if (array_key_exists('is_highest', $edu)) {
                    $edu['is_highest'] = filter_var($edu['is_highest'], FILTER_VALIDATE_BOOLEAN);
                } else {
                    $edu['is_highest'] = false;
                }

                return $edu;
            }, $this->input('educations'));

            $this->merge(['educations' => $normalized]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $employeeId = $this->route('employee');
        $employee = EmployeeProfile::find($employeeId);
        $userId = $employee?->user_id;
        $currentYear = (int) date('Y');

        return [
            // User fields
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'roles' => ['sometimes', 'required', 'array'],
            'roles.*' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'sanctum')],

            // Employee Profile fields
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('employee_profiles', 'code')->ignore($employeeId)],
            'identity_number' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('employee_profiles', 'identity_number')->ignore($employeeId)],
            'phone' => ['sometimes', 'required', 'string', 'max:20'],
            'date_of_birth' => ['sometimes', 'required', 'date', 'before:today'],
            'gender' => ['sometimes', 'required', 'string', 'in:'.implode(',', array_column(Gender::cases(), 'value'))],
            'hobby' => ['nullable', 'string', 'max:255'],
            'place_of_birth' => ['sometimes', 'required', 'string', 'max:100'],
            'address' => ['sometimes', 'required', 'string'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'required', 'string', 'max:10'],
            'preferred_language' => ['nullable', 'string', 'max:50'],
            'additional_notes' => ['nullable', 'string'],

            // Job Information fields
            'job_title' => ['sometimes', 'required', 'string', 'max:255'],
            'team_id' => ['nullable', 'exists:teams,id'],
            'department' => ['nullable', 'string', 'in:'.implode(',', array_column(Department::cases(), 'value'))],
            'store_id' => ['nullable', 'exists:stores,id'],
            'years_experience' => ['sometimes', 'required', 'integer', 'min:0'],
            'status' => ['sometimes', 'required', 'string', 'in:'.implode(',', array_column(JobStatus::cases(), 'value'))],
            'employment_type' => ['sometimes', 'required', 'string', 'in:'.implode(',', array_column(EmploymentType::cases(), 'value'))],
            'vendor_name' => ['required_if:employment_type,vendor', 'nullable', 'string', 'max:255'],
            'work_location' => ['sometimes', 'required', 'string', 'in:'.implode(',', array_column(WorkLocation::cases(), 'value'))],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['required_if:status,resigned', 'nullable', 'date'],
            'monthly_salary' => ['sometimes', 'required', 'numeric', 'min:0'],
            'skill_level' => ['sometimes', 'required', 'string', 'in:'.implode(',', array_column(SkillLevel::cases(), 'value'))],

            // Bank Information fields
            'bank_name' => ['sometimes', 'required', 'string', 'in:'.implode(',', array_column(BankName::cases(), 'value'))],
            'account_number' => ['sometimes', 'required', 'string', 'max:50'],
            'account_holder_name' => ['sometimes', 'required', 'string', 'max:255'],
            'bank_branch' => ['nullable', 'string', 'max:255'],
            'account_type' => ['nullable', 'string', 'in:'.implode(',', array_column(AccountType::cases(), 'value'))],

            // Education History fields (array)
            'educations' => ['sometimes', 'required', 'array', 'min:1'],
            'educations.*.id' => ['nullable', 'integer', 'exists:employee_educations,id'],
            'educations.*.level' => ['required', 'string', 'in:'.implode(',', array_column(EducationLevel::cases(), 'value'))],
            'educations.*.institution' => ['required', 'string', 'max:255'],
            'educations.*.major' => ['nullable', 'string', 'max:255'],
            'educations.*.start_year' => ['required', 'integer', 'min:1950', "max:{$currentYear}"],
            'educations.*.end_year' => ['nullable', 'integer', 'min:1950', "max:{$currentYear}", 'gte:educations.*.start_year'],
            'educations.*.gpa' => ['nullable', 'numeric', 'min:0', 'max:4'],
            'educations.*.is_highest' => ['required', 'boolean'],

            // Emergency Contacts fields (array)
            'emergency_contacts' => ['sometimes', 'required', 'array', 'min:1'],
            'emergency_contacts.*.id' => ['nullable', 'integer', 'exists:emergency_contacts,id'],
            'emergency_contacts.*.full_name' => ['sometimes', 'string', 'max:255'],
            'emergency_contacts.*.relationship' => ['sometimes', 'string', 'max:100'],
            'emergency_contacts.*.phone' => ['sometimes', 'string', 'max:20'],
            'emergency_contacts.*.email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function attributes()
    {
        return [
            // User attributes
            'name' => 'Name',
            'email' => 'Email',
            'password' => 'Password',
            'user_profile_photo' => 'User Profile Photo',

            // Employee Profile attributes
            'code' => 'Employee Code',
            'identity_number' => 'Identity Number',
            'phone' => 'Phone Number',
            'date_of_birth' => 'Date of Birth',
            'gender' => 'Gender',
            'hobby' => 'Hobby',
            'place_of_birth' => 'Place of Birth',
            'address' => 'Address',
            'city' => 'City',
            'postal_code' => 'Postal Code',
            'profile_photo' => 'Profile Photo',
            'preferred_language' => 'Preferred Language',
            'additional_notes' => 'Additional Notes',

            // Job Information attributes
            'job_title' => 'Job Title',
            'team' => 'Team',
            'years_experience' => 'Years of Experience',
            'status' => 'Job Status',
            'employment_type' => 'Employment Type',
            'work_location' => 'Work Location',
            'start_date' => 'Start Date',
            'monthly_salary' => 'Monthly Salary',
            'skill_level' => 'Skill Level',

            // Bank Information attributes
            'bank_name' => 'Bank Name',
            'account_number' => 'Account Number',
            'account_holder_name' => 'Account Holder Name',
            'bank_branch' => 'Bank Branch',
            'account_type' => 'Account Type',

            // Emergency Contacts attributes
            'emergency_contacts' => 'Emergency Contacts',
            'emergency_contacts.*.full_name' => 'Full Name',
            'emergency_contacts.*.relationship' => 'Relationship',
            'emergency_contacts.*.phone' => 'Phone Number',
            'emergency_contacts.*.email' => 'Email',

            // Education attributes
            'educations' => 'Education History',
            'educations.*.level' => 'Education Level',
            'educations.*.institution' => 'Institution',
            'educations.*.major' => 'Major',
            'educations.*.start_year' => 'Start Year',
            'educations.*.end_year' => 'End Year',
            'educations.*.gpa' => 'GPA',
            'educations.*.is_highest' => 'Highest Education',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $educations = $this->input('educations');
            if ($this->has('educations') && is_array($educations) && count($educations) > 0) {
                $highestCount = count(array_filter($educations, fn ($e) => is_array($e) && ! empty($e['is_highest'])));
                if ($highestCount !== 1) {
                    $validator->errors()->add('educations', 'Exactly one education entry must be marked as the highest education.');
                }
            }
        });
    }
}
