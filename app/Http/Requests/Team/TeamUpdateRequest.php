<?php

namespace App\Http\Requests\Team;

use App\Enums\Department;
use App\Enums\TeamStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

        if ($this->has('department') && $this->input('department') === '') {
            $this->merge(['department' => null]);
        }

        if ($this->has('remove_icon')) {
            $this->merge([
                'remove_icon' => filter_var($this->input('remove_icon'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    public function rules(): array
    {
        $team = $this->route('team');
        $teamId = $team instanceof \App\Models\Team ? $team->id : $team;
        $effectiveStoreId = $this->input('store_id', $team instanceof \App\Models\Team ? $team->store_id : null);

        return [
            'store_id' => ['sometimes', 'required', 'integer', 'exists:stores,id'],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('teams', 'name')
                    ->where(function ($query) use ($effectiveStoreId) {
                        return $query->where('store_id', $effectiveStoreId)->whereNull('deleted_at');
                    })
                    ->ignore($teamId),
            ],
            'expected_size' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'icon' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_icon' => ['sometimes', 'boolean'],
            'department' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_column(Department::cases(), 'value'))],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(TeamStatus::cases(), 'value'))],
            'team_lead_id' => ['nullable', 'exists:users,id'],
            'responsibilities' => ['sometimes', 'nullable', 'array'],
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
