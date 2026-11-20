<?php

namespace App\Http\Requests;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStoreRequest extends FormRequest
{
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
