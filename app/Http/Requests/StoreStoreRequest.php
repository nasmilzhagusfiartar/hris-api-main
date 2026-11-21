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

        if ($this->has('code') && is_string($this->input('code')) && trim($this->input('code')) === '') {
            $merge['code'] = null;
        }

        if ($this->has('address') && is_string($this->input('address')) && trim($this->input('address')) === '') {
            $merge['address'] = null;
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
        return [
            'code' => ['nullable', 'string', 'max:50', 'unique:stores,code'],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', Rule::in(Store::BRANDS)],
            'city' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
