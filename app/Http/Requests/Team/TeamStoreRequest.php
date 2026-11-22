<?php

namespace App\Http\Requests\Team;

use App\Enums\Department;
use App\Enums\TeamStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeamStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('team_lead_id') && $this->input('team_lead_id') === '') {
            $this->merge(['team_lead_id' => null]);
        }
        if ($this->has('department') && $this->input('department') === '') {
            $this->merge(['department' => null]);
        }
    }

    public function rules(): array
    {
        $storeId = $this->input('store_id');

        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('teams', 'name')->where(function ($query) use ($storeId) {
                    return $query->where('store_id', $storeId)->whereNull('deleted_at');
                }),
            ],
            'expected_size' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', new \App\Rules\SafeImageFile],
            'department' => ['nullable', 'string', 'in:'.implode(',', array_column(Department::cases(), 'value'))],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(TeamStatus::cases(), 'value'))],
            'team_lead_id' => ['nullable', 'exists:users,id'],
            'responsibilities' => ['nullable', 'array'],
            'responsibilities.*' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A team with this name already exists in the selected location.',
        ];
    }
}
