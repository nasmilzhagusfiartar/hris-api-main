<?php

namespace App\Http\Requests;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('code') && is_string($this->input('code')) && trim($this->input('code')) === '') {
            $merge['code'] = null;
        }

        if ($this->has('address') && is_string($this->input('address')) && trim($this->input('address')) === '') {
            $merge['address'] = null;
        }

        if ($this->has('head_user_id') && ($this->input('head_user_id') === '' || $this->input('head_user_id') === null)) {
            $merge['head_user_id'] = null;
        }

        if ($this->has('is_active') && ! is_bool($this->input('is_active'))) {
            $parsed = filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                $merge['is_active'] = $parsed;
            }
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $storeId = $this->route('store');
        $existingStore = Store::find($storeId);
        $effectiveType = $this->input('type', $existingStore?->type ?? Store::TYPE_STORE);

        $brandRules = $effectiveType === Store::TYPE_HEAD_OFFICE
            ? ['sometimes', 'required', 'string', 'max:255']
            : ['sometimes', 'required', 'string', Rule::in(Store::BRANDS)];

        return [
            'code' => ['nullable', 'string', 'max:50', Rule::unique('stores', 'code')->ignore($storeId)],
            'type' => ['sometimes', 'required', 'string', Rule::in(Store::TYPES)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'brand' => $brandRules,
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'head_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $storeId = $this->route('store');
            $existingStore = Store::find($storeId);
            $effectiveType = $this->input('type', $existingStore?->type ?? Store::TYPE_STORE);
            $effectiveActive = $this->has('is_active')
                ? (bool) $this->input('is_active')
                : (bool) ($existingStore?->is_active ?? true);

            if ($effectiveType === Store::TYPE_HEAD_OFFICE && $effectiveActive) {
                $exists = Store::where('type', Store::TYPE_HEAD_OFFICE)
                    ->where('is_active', true)
                    ->where('id', '!=', $storeId)
                    ->exists();
                if ($exists) {
                    $validator->errors()->add('type', 'Only one active Head Office location is allowed.');
                }
            }
        });
    }
}
