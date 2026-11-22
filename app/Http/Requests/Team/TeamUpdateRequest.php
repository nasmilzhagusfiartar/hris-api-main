<?php

namespace App\Http\Requests\Team;

use App\Enums\Department;
use App\Enums\TeamStatus;
use Illuminate\Foundation\Http\FormRequest;

class TeamUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('responsibilities') && is_array($this->input('responsibilities'))) {
            $cleaned = array_values(array_filter(
                $this->input('responsibilities'),
                fn ($item) => is_string($item) && trim($item) !== ''
            ));
            $this->merge(['responsibilities' => $cleaned]);
        }

        if ($this->has('team_lead_id') && $this->input('team_lead_id') === '') {
            $this->merge(['team_lead_id' => null]);
        }

        if ($this->has('remove_icon')) {
            $this->merge([
                'remove_icon' => filter_var($this->input('remove_icon'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'expected_size' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'icon' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
            'remove_icon' => ['sometimes', 'boolean'],
            'department' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_column(Department::cases(), 'value'))],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(TeamStatus::cases(), 'value'))],
            'team_lead_id' => ['nullable', 'exists:users,id'],
            'responsibilities' => ['sometimes', 'nullable', 'array'],
            'responsibilities.*' => ['nullable', 'string'],
        ];
    }

    public function attributes()
    {
        return [
            'name' => 'Team Name',
            'expected_size' => 'Expected Team Size',
            'description' => 'Team Description',
            'icon' => 'Team Icon',
            'department' => 'Department',
            'status' => 'Status',
            'team_lead_id' => 'Team Lead',
            'responsibilities' => 'Responsibilities',
        ];
    }
}
