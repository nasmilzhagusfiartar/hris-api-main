<?php

namespace App\Http\Requests;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->has('type') || trim((string) $this->input('type')) === '') {
            $merge['type'] = Store::TYPE_STORE;
        }

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
        $type = $this->input('type', Store::TYPE_STORE);
        $brandRules = $type === Store::TYPE_HEAD_OFFICE
            ? ['required', 'string', 'max:255']
            : ['required', 'string', Rule::in(Store::BRANDS)];

        return [
            'code' => ['nullable', 'string', 'max:50', 'unique:stores,code'],
            'type' => ['required', 'string', Rule::in(Store::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'brand' => $brandRules,
            'city' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'head_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $type = $this->input('type', Store::TYPE_STORE);
            $isActive = $this->has('is_active') ? (bool) $this->input('is_active') : true;

            if ($type === Store::TYPE_HEAD_OFFICE && $isActive) {
                $exists = Store::where('type', Store::TYPE_HEAD_OFFICE)
                    ->where('is_active', true)
                    ->exists();
                if ($exists) {
                    $validator->errors()->add('type', 'Only one active Head Office location is allowed.');
                }
            }
        });
    }
}
